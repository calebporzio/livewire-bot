<?php

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

Route::post('/webhook', function () {
    $hash = request()->header('X-Hub-Signature-256');
    $rawPayload = file_get_contents('php://input');
    $computedHash = 'sha256='.hash_hmac('sha256', $rawPayload, env('GITHUB_WEBHOOK_SECRET'));

    if (! hash_equals($computedHash, $hash)) throw new \Exception('Hook secret does not match.');

    $event = request()->header('X-GitHub-Event');
    $action = request('action');

    if ($event === 'issue_comment' && $action === 'created') return checkForReOpenComment(request('issue'), request('comment'));
    if ($event === 'issues' && $action === 'closed') return autoReplyForReOpen(request('issue'));
});

function checkForReOpenComment($issue, $comment)
{
    if (trim($comment['body']) === 'REOPEN' && $issue['state'] === 'closed') {
        // Re-open issue
        $response = Http::withToken(env('GITHUB_TOKEN'))->patch('https://api.github.com/repos/livewire/livewire/issues/'.$issue['number'], [
            'state' => 'open',
        ]);
    }
}

function autoReplyForReOpen($issue)
{
    // Comment
    Http::withToken(env('GITHUB_TOKEN'))->post('https://api.github.com/repos/livewire/livewire/issues/'.$issue['number'].'/comments', [
        'body' => <<<EOT
👋 Oh Hi! I'm Squishy, the friendly jellyfish that manages Livewire issues.

I see this issue has been closed.

Here in the Livewire repo, we have an "issues can be closed guilt-free and without explanation" policy.

If for ANY reason you think this issue hasn't been resolved, PLEASE feel empowered to re-open it.

Re-opening actually helps us track which issues are a priority.

Reply "REOPEN" to this comment and we'll happily re-open it for you!

(More info on this philosophy here: https://twitter.com/calebporzio/status/1321864801295978497)
EOT
    ]);
}
