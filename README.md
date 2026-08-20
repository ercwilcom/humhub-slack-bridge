# Slack Bridge for HumHub

Mirrors chosen Slack channels into Space streams, posted under the matching
member's own name.

Communities that ran on Slack before they ran on HumHub tend to keep running on
Slack. This bridge lets the conversation carry over instead of asking people to
move — a channel appears in a Space, signed by whoever wrote it, and stays in
step as messages are edited and deleted.

| | |
|---|---|
| **Trigger** | any top-level message in a channel with an active rule |
| **Author here** | the member whose HumHub email matches their Slack email |
| **No match** | the message is skipped, silently to readers |
| **Transport** | Slack Events API webhook — near real time |
| **Attachments** | fetched and attached to the post |
| **Visibility** | public in the HumHub sense |

Deliberately skipped: thread replies, bot and integration messages, service
messages (joins, leaves, pins), and any channel without a rule.

## Two things worth knowing before you install it

**The author must already exist here.** Matching is Slack email ↔ HumHub email.
With no match the message is dropped. The consequence to accept: a channel
mirrors *partially*, and the stream does not say so. The admin page counts these
so the gap is visible somewhere — read it before concluding that a channel came
across whole.

**The mirror can retract.** Copying a channel wholesale makes public what was
written in a conversational register. Slack edits and deletions are therefore
propagated: deleting there deletes here. Without that, the only way to take back
an unfortunate sentence would be to go through a HumHub administrator.

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
php protected/yii slack-bridge/backfill   # bring in a channel's history
```

An hourly cron sweep retries anything that failed in flight. The normal path is
inline, right after answering Slack's 200.

## Security

The webhook is public by necessity — Slack calls it, not a signed-in member.
What holds the door is Slack's HMAC signature, checked on every request against
the signing secret, with a timestamp window against replay. See
`services/SlackSignature.php`.

## Licence

AGPL-3.0-or-later. See `LICENSE`.
