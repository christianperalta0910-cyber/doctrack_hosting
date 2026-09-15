<?php

namespace App\Http\Controllers;

use App\Events\UserVerified;
use App\Http\Controllers\Concerns\ThrottlesAttempts;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    use ThrottlesAttempts;

    public function showLogin()
    {
        return view('auth.login');
    }

    /**
     * Plain email + password — no second factor. This app used to require
     * an emailed 6-digit code (plus a Sign In Backup Codes fallback for
     * when that email didn't arrive) on top of the password; both were
     * removed together, since the backup codes existed solely as a
     * fallback for the emailed code and have no purpose without it. Kept
     * deliberately simple; see git history for the prior 2FA + backup
     * codes implementation if it needs to be rebuilt.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:100'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $throttleKey = $this->throttleKeyFor('login', $credentials['email'], $request);

        if (($seconds = $this->secondsLockedOut($throttleKey)) !== null) {
            // login_retry_after is flashed separately from the error string
            // (rather than only embedding the number in the message) so the
            // view can drive a live client-side countdown instead of a
            // number that's already stale by the time the page renders.
            return back()->withErrors([
                'email' => "Too many login attempts. Try again in {$seconds} second(s).",
            ])->with('login_retry_after', $seconds)->onlyInput('email');
        }

        // Eloquent/Auth facade builds a parameterized query internally — no
        // raw SQL string interpolation of user input anywhere in this app.
        if (Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            $user = Auth::user();

            // Correct credentials are not enough on their own — the account
            // must have been verified at least once (see User::
            // sendEmailVerificationNotification(), sent the moment an admin
            // creates the account). Undo the login rather than ever letting
            // an unverified session start.
            if (!$user->hasVerifiedEmail()) {
                Auth::logout();

                return back()->withErrors([
                    'email' => 'Please verify your email before logging in — check your inbox for the verification link we sent when your account was created.',
                ])->onlyInput('email');
            }

            RateLimiter::clear($throttleKey);
            $request->session()->regenerate();

            AuditLog::record($user->user_id, null, 'login', "User {$user->username} logged in.");

            return redirect()->intended($this->dashboardRouteFor($user->role));
        }

        $hits = $this->recordFailedAttempt($throttleKey);

        // Always name the remaining count, including the 0 case (this
        // attempt just used the last slot) — falling back to a bare
        // "Invalid credentials." on that one attempt would silently
        // drop the only warning the user gets before the next failure
        // locks them out.
        $remaining = $this->maxAttempts - $hits;
        $errorMessage = $remaining > 0
            ? "Invalid credentials. {$remaining} attempt(s) remaining before temporary lockout."
            : 'Invalid credentials. This was your last attempt — the next failure will trigger a temporary lockout.';

        return back()->withErrors(['email' => $errorMessage])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        $user = Auth::user();
        if ($user) {
            AuditLog::record($user->user_id, null, 'logout', "User {$user->username} logged out.");
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Signed-link handler for the email sent by User::
     * sendEmailVerificationNotification() — deliberately reachable by a
     * guest (no auth middleware): this app blocks login entirely until
     * verified (see login() above), so the person clicking this link
     * cannot already be authenticated. The signature itself (id + sha1 of
     * the email, expiring) is what proves it's legitimate, not a session.
     */
    public function verifyEmail(Request $request, int $id, string $hash)
    {
        abort_unless($request->hasValidSignature(), 403, 'This verification link is invalid or has expired.');

        $user = User::findOrFail($id);

        abort_unless(hash_equals((string) $hash, sha1($user->getEmailForVerification())), 403, 'This verification link is invalid.');

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
            event(new UserVerified($user));

            AuditLog::record(null, null, 'email_verified', "Account #{$user->user_id} ({$user->username}) verified its email.");
        }

        return redirect()->route('login')->with('status', 'Your account is verified — you can now log in.');
    }

    public function showForgotPassword()
    {
        return view('auth.forgot-password');
    }

    /**
     * Deliberately reports success regardless of whether the email matches
     * a real account — a distinct "no account found" error would let
     * anyone probe which emails are registered in the system.
     */
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        return back()->with('status', 'If that email is registered, a password reset link is on its way to it.');
    }

    public function showResetForm(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            // mixedCase()+numbers() require actual complexity, not just
            // length; uncompromised() checks the password against known
            // data breaches via a k-anonymity API (only a partial hash is
            // ever sent, never the real password) — a password can be
            // 8+ characters and still be "password1234", which this
            // blocks that the plain min:8 rule alone never caught.
            'password' => ['required', 'string', PasswordRule::min(8)->mixedCase()->numbers()->uncompromised(), 'confirmed'],
        ]);

        $status = Password::reset($validated, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password)])->save();

            AuditLog::record(null, null, 'password_reset', "Account #{$user->user_id} ({$user->username}) reset its own password.");
        });

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => __($status)]);
        }

        return redirect()->route('login')->with('status', 'Your password has been reset — you can now log in.');
    }

    private function dashboardRouteFor(string $role): string
    {
        return match ($role) {
            'admin' => route('admin.dashboard'),
            'approver' => route('approver.dashboard'),
            default => route('originator.dashboard'),
        };
    }
}
