<?php

use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
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
    if ($event === 'release' && $action === 'created') return autoFillOutRelease(request('release'));
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

function autoFillOutRelease($release)
{
    if (! $release['draft']) return;

    $releases = Http::withToken(env('GITHUB_TOKEN'))->get('https://api.github.com/repos/livewire/livewire/releases')->json();

    $lastRelease = collect($releases)->first(function ($r) use ($release) {
        return ! $r['draft'] && $r['target_commitish'] === $release['target_commitish'];
    });

    $since = $lastRelease['published_at'];

    $pulls = Http::withToken(env('GITHUB_TOKEN'))->get('https://api.github.com/repos/livewire/livewire/pulls', ['state' => 'closed', 'sort' => 'updated', 'direction' => 'desc', 'base' => $release['target_commitish']])->json();

    $references = collect($pulls)->filter(function($pull) use ($lastRelease) {
        if (! $pull['merged_at']) return false;

        return Carbon::parse($pull['merged_at'])->isAfter(Carbon::parse($lastRelease['published_at']));
    })->map(function ($pull) {
        return '* '.$pull['title'].' ['.$pull['number'].']('.$pull['url'].')';
    })->implode("\n");

    Http::withToken(env('GITHUB_TOKEN'))->patch('https://api.github.com/repos/livewire/livewire/releases/'.$release['id'], [
        'body' => "## Added\n\n## Fixed\n\n".$references,
    ])->json();

    return $references;
}
