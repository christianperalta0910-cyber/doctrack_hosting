<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum review time before deciding
    |--------------------------------------------------------------------------
    |
    | An approver (or an admin deciding directly on Unassigned Documents,
    | SLA Override, ML Review, or Readability Review) must have spent at
    | least this many seconds actually viewing a document — real time from
    | DocumentReviewSession, not just clicking through — before Approve/
    | Reject is accepted. A flat, low floor rather than something scaled
    | to document length: it only rules out a literal zero-effort decision
    | made without ever opening the file. It can't prove genuine reading
    | happened, only that the viewer was open this long — the audit trail
    | (who reviewed what, for how long) is what makes a suspiciously fast
    | decision visible for a human to actually judge, not this number.
    |
    */

    'min_review_seconds' => 10,

];
