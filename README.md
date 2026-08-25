# Slack Bridge for HumHub

Mirrors chosen Slack channels into Space streams, posted under the matching
member's own name.

Communities that ran on Slack before they ran on HumHub tend to keep running on
Slack. This bridge lets the conversation carry over instead of asking people to
move — a channel appears in a Space, signed by whoever wrote it, dated when it
was written, threads and all, and stays in step as messages are edited and
deleted.

| | |
|---|---|
| **Trigger** | any message in a channel with an active rule |
| **Top-level message** | becomes a post |
| **Thread reply** | becomes a comment on that post |
| **Author here** | the member whose HumHub email matches their Slack email |
| **No match** | the message is skipped, silently to readers |
| **Transport** | Slack Events API webhook — near real time |
| **Attachments** | fetched and attached to the post or comment |
| **Timestamps** | taken from Slack, never from the moment of import |
| **Visibility** | public in the HumHub sense |

Deliberately skipped: bot and integration messages, service messages (joins,
leaves, pins), and any channel without a rule.

## Four things worth knowing before you install it

**The author must already exist here.** Matching is Slack email ↔ HumHub email.
With no match the message is dropped. The consequence to accept: a channel
mirrors *partially*, and the stream does not say so. The admin page counts these
so the gap is visible somewhere — read it before concluding that a channel came
across whole.

When the two addresses simply differ, *Administration → Slack Bridge →
Authors to pair* is the answer: it records the correspondence and changes
neither address. That matters — the address on an account here may tie it to an
identity somewhere else, so "fixing" a mirror by editing it can break a login.
Pairing someone also brings their already-skipped messages across, silently and
at their original date. A workspace's role and team accounts get the other
decision, *never pair*: pairing one would publish under the name of a member who
wrote nothing.

**The mirror can retract.** Copying a channel wholesale makes public what was
written in a conversational register. Slack edits and deletions are therefore
propagated: deleting there deletes here. Without that, the only way to take back
an unfortunate sentence would be to go through a HumHub administrator.

**A thread becomes a comment thread.** A reply hangs off the post its opening
message produced, which is the only shape that keeps a conversation readable —
five independent posts in a stream are five strangers. Two consequences follow
from it. A reply whose opening message was never mirrored (unmatched author, an
"images only" rule, a channel wired up later) has nothing here to hang on, and
is skipped under its own reason: mirroring it as a fresh post would publish, in
somebody's name, a message the bridge had deliberately left out. And the rule's
own conditions — "images only", the topic — apply to what *opens* a
conversation, not to what is said in reply to it.

**Timestamps come from Slack.** A post and a comment carry the time their
message was written, not the time it was copied. In real time the difference is
a few seconds, which is exactly what makes it easy to miss: the day the webhook
is down for an hour, the day the hourly sweep catches up, the day a 30 MB
attachment holds the import — that is when the only honest date is Slack's. It
also keeps HumHub's "edited" pencil truthful: it appears when Slack says the
message was edited, and not before.

## Setup

1. Create a Slack app. `slack-app-manifest.yml` in this directory is a starting
   manifest — it declares the scopes and the event subscription.
2. Scopes: `channels:history`, `groups:history`, `channels:read`, `groups:read`,
   `users:read`, `users:read.email`, `files:read`.
   `users:read.email` is **not** optional: the email address is what ties a
   Slack author to an account here. Without it, everything is skipped.
3. Enable the module, then open *Administration → Slack Bridge* and save the bot
   token (`xoxb-…`) and the signing secret. Until both are saved the endpoint
   refuses everything that reaches it.
4. Paste the webhook URL shown on that page into the Slack app's
   *Event Subscriptions*, and subscribe to `message.channels` and
   `message.groups`.
5. Add a rule per channel. A channel with no active rule is never mirrored —
   the rule list is the allow-list.

## The "Via Slack" topic

Every mirrored post carries it, on top of the rule's own topic if there is one.
It answers the one question the stream cannot ask for itself: *where did this
come from?* Without it, a mirrored post and a post written here are
indistinguishable.

It is an ordinary HumHub topic, so it belongs **to its container**: "Via Slack"
exists once per Space being fed. One word to a reader, several rows in the
database.

> **`Topic::attach()` REPLACES the topic list, it does not extend it.** The
> rule's topic and this label are therefore attached in a single call
> (`applyTopics()`). Attaching them one after the other silently erases the
> first — and only on channels that have a rule topic, which is exactly the
> kind of bug that ships.

To label posts mirrored before this existed:
`php protected/yii slack-bridge/retag`.

## Rules

A rule says what becomes of a channel's messages. Rules are created and edited
in the admin screen; nothing lives in code, so changing a destination or adding
a channel needs no deployment.

A message can go to **a Space's stream** or to **the author's own profile**
(useful for a general noticeboard channel with no Space behind it). A rule can
also apply a topic to every post, and can require that a message carry an image.

## Console

```
php protected/yii slack-bridge/status     # what is configured, and what has come through
php protected/yii slack-bridge/channels   # list channels the bot can see
php protected/yii slack-bridge/retry      # re-run events left pending
php protected/yii slack-bridge/backfill   # bring in a channel's history, threads included
php protected/yii slack-bridge/redate     # re-stamp what was mirrored before dates came from Slack
php protected/yii slack-bridge/unpaired  # who writes here without a matching account
php protected/yii slack-bridge/pair      # pair a Slack author to an account (or never pair it)
php protected/yii slack-bridge/replay    # re-examine skips whose reason has been lifted
```

`backfill` walks each thread right after the message that opens it — replies
need their post to exist before they have anywhere to go. It is re-runnable: a
message already mirrored is recognised and skipped, so running it after an
upgrade brings across the replies of the threads it had already mirrored.
History imports are silent and back-dated; nobody is notified about a
conversation that ended two months ago.

`redate` is the one-off catch-up for an installation that mirrored anything
before timestamps came from Slack: it re-stamps every mirrored post and comment
from the message that produced it. A message Slack recorded as edited keeps its
edit time, so the pencil that is *true* survives. Run it once after upgrading.

`replay` is the counterpart to pairing, and the cheaper half of catching up: a
skipped message is still in the registry, payload and all, so re-examining it
costs no Slack call and works **past the 90-day wall** — what history can no
longer be asked for, the registry already holds. It only reconsiders skips whose
reason can be lifted by a human (no matching author, orphaned reply, no rule for
the channel, "images only"); a bot message or an empty one is never revisited.
Re-running `backfill` reconsiders them too.

An hourly cron sweep retries anything that failed in flight — technical failures
only. A skip is not a failure: it is the consequence of a state of the world, so
re-examining one is a deliberate act, never a background loop.

## Security

The webhook is public by necessity — Slack calls it, not a signed-in member.
What holds the door is Slack's HMAC signature, checked on every request against
the signing secret, with a timestamp window against replay. See
`services/SlackSignature.php`.

## Licence

AGPL-3.0-or-later. See `LICENSE`.
