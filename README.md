# System Email Bundle

Make the recipients and the on/off switch of your application's transactional
emails configurable from a backend UI, without redeploying. You declare the
email **types** (the arbitrary functionalities that send mail) centrally in
YAML; admins manage **recipients** (TO/CC/BCC) and flip a per-type **send
kill-switch** in the browser. Delivery goes through Symfony Mailer.

The bundle owns the configuration and the recipient model. Message content
(subject and HTML body) stays in your application code - this bundle answers
"who receives this, and is it turned on?".

Optionally it also keeps an **outgoing e-mail log**: every message sent through
Symfony Mailer, with its status and delivery attempts, and a resend.

- PHP `>= 8.1`
- Symfony `5.4`, `6.x`, `7.x`, `8.x`
- Doctrine ORM `2.9+` / `3.x`

## Install

```bash
composer require hexis/system-email-bundle
```

Register the bundle (Symfony Flex does this automatically):

```php
// config/bundles.php
return [
    // ...
    Hexis\SystemEmailBundle\SystemEmailBundle::class => ['all' => true],
];
```

## Configure

```yaml
# config/packages/system_email.yaml
system_email:
    from:
        address: '%env(MAIL_FROM)%'
        name: '%env(MAIL_FROM_NAME)%'
    async: true                 # dispatch via Messenger when a bus is available
    default_type_enabled: true  # enabled value for newly synced types
    cache:
        enabled: true
        pool: cache.app
    admin:
        route_prefix: /admin/system-emails
        role: ROLE_ADMIN
        base_template: '@SystemEmail/layout.html.twig'
    mail_log:
        enabled: false          # see "Outgoing e-mail log"
        table_name: system_email_log
    types:
        sap_alert: { label: 'SAP Alert' }
        booking:   { label: 'Booking notifications' }
        no_reply:  { label: 'No-reply sender' }
```

`types` is the single source of truth for which email types exist. Add a key
here, redeploy, run the sync command, and it becomes manageable in the UI.

## Database schema

The bundle ships Doctrine-mapped entities (`system_email_type`,
`system_email_recipient`, and the e-mail log table, `system_email_log` by
default) and registers their mapping automatically. Generate a
migration in your app:

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

## Sync types (wire into deploy)

```bash
bin/console system-email:sync
```

Idempotent: creates missing types, updates changed labels, and **never**
overwrites the admin-managed `enabled` flag. Run it on every deploy so newly
declared types appear in the UI. `--prune` removes types (and their recipients)
that are no longer in configuration.

## Mount the admin UI

```yaml
# config/routes/system_email.yaml
system_email:
    resource: '@SystemEmailBundle/config/routes.php'
    prefix: /admin/system-emails
```

Secure the prefix in your firewall. The controller also enforces the configured
`admin.role` when a security layer is present.

## Usage

Resolve recipients and build your own message:

```php
use Hexis\SystemEmailBundle\Service\SystemEmailManager;

public function __construct(private SystemEmailManager $systemEmails) {}

$recipients = $this->systemEmails->getRecipients('sap_alert'); // Recipient[] (TO/CC/BCC)
$emails     = $this->systemEmails->getEmails('sap_alert');     // string[]
if ($this->systemEmails->isEnabled('sap_alert')) {
    // ...
}
```

Or let the bundle build and send it, honouring the kill-switch:

```php
use Hexis\SystemEmailBundle\Service\SystemEmailMailer;
use Hexis\SystemEmailBundle\Model\Attachment;
use Hexis\SystemEmailBundle\Model\Recipient;
use Hexis\SystemEmailBundle\Enum\RecipientType;

public function __construct(private SystemEmailMailer $mailer) {}

$this->mailer->send(
    'sap_alert',
    'Service Layer Alert',
    $this->renderView('emails/sap_alert.html.twig', ['error' => $error]),
    attachments: [new Attachment($pdfBytes, 'report.pdf', 'application/pdf')],
    extraRecipients: [Recipient::fromType($user->getEmail(), $user->getName(), RecipientType::TO)],
);
```

`send()` returns `false` (and delivers nothing) when the type is disabled or has
no recipients; `true` when the message was sent or queued. When `async` is on and
Symfony Messenger is installed, mail is dispatched as `SendEmailMessage`;
otherwise it is sent in-request. Pass `reference: 'invoice:42'` to record the
business record the e-mail is about in the e-mail log.

## Outgoing e-mail log

Off by default. Turn it on with `mail_log.enabled: true` once the table exists
(requires `symfony/mailer` 6.2 or later). Every e-mail sent through Symfony
Mailer is then logged, whether it goes through `SystemEmailMailer` or
`MailerInterface` directly, in-request or through a Messenger queue.

### Migration

Your application owns the migrations. Set `mail_log.table_name` first if you
want another name, then generate and run one:

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

The table belongs to the bundle schema whether or not the log is enabled. Enable
the log only after the migration ran: a log write that fails is reported through
the PSR logger and never stops the e-mail, but nothing is logged.

### What is logged

One `Hexis\SystemEmailBundle\Entity\SystemEmailLog` row per message:

| Field | Content |
|-------|---------|
| `correlationId` | Id carried by the message in the `X-System-Email-Id` header; ties the queueing and every attempt to one row. |
| `type`, `reference` | System e-mail type key and the record the e-mail is about (see below). |
| `sender`, `recipients`, `subject` | From; To, Cc and Bcc addresses, comma-separated; subject. |
| `status` | `queued`, `sent` or `failed` (`EmailLogStatus`). A message waiting for a Messenger retry is `queued` again. |
| `attemptCount`, `attempts`, `lastError` | Number of delivery attempts; every attempt (time, status, error); the last error. |
| `transportMessageId` | Id the transport returned for the successful attempt. |
| `getStoredMessage()` | The message as handed to the transport (rendered, before DKIM/S/MIME signing): headers, body, attachments. Used for display and resend. |
| `resentFrom` | The entry this one resends. |
| `createdAt`, `updatedAt`, `sentAt` | Timestamps. |

How it is fed, from Mailer and Messenger events:

- `SendMessageToTransportsEvent`: a `SendEmailMessage` is queued. The entry is
  created as `queued`; the queued copy gets the correlation header (the caller's
  message object is not modified).
- `MessageEvent` (not queued): an attempt starts. A message sent without a queue
  gets its entry here; before every attempt of a queued one, sender,
  recipients, subject and stored message are refreshed from the rendered message.
- `SentMessageEvent` / `FailedMessageEvent`: the attempt is recorded.
- `WorkerMessageFailedEvent`: a worker records its failed attempt here, after the
  handler is done, so a `doctrine_transaction` middleware that rolls back the
  handler does not erase it. This also covers failures before the transport (e.g.
  an invalid message). While Messenger retries, the status goes back to `queued`;
  once retries are exhausted it stays `failed`.

Rows are written through DBAL on the entity manager's connection, never through
the ORM unit of work, so logging never flushes your pending changes; the
statements join whatever transaction is open, like the Doctrine Messenger
transport, in a savepoint: a log write that fails is rolled back alone and leaves
your transaction usable (PostgreSQL aborts a transaction on a failed statement).
Send mail outside your business transactions (or queue it on that connection): a
sent e-mail whose transaction rolls back loses its entry. An attempt is counted
under a row lock, so workers that handle the same message at once lose none.

**Type and reference.** `SystemEmailMailer::send()` sets the type (its key) and
the optional `reference`. For mail you send through `MailerInterface`, add the
headers yourself; both are removed before delivery:

```php
use Hexis\SystemEmailBundle\Entity\SystemEmailLog;

$email->getHeaders()->addTextHeader(SystemEmailLog::HEADER_TYPE, 'password_reset');
$email->getHeaders()->addTextHeader(SystemEmailLog::HEADER_REFERENCE, 'user:42');
```

Limits:

- A bare `RawMessage` (not a `Mime\Message`) cannot carry the header and is not
  logged.
- Messages queued before the log was enabled (or by a producer without this
  bundle) carry no correlation id: each attempt gets its own entry. Drain the
  queues, failure transport included, before enabling the log.
- Without a queue, a message that fails before the transport (e.g. no From, a
  signer error) throws to the caller and its entry stays `queued`; with a
  failover/round-robin transport and no queue, each transport tried gets its own
  entry. Through a Messenger queue both are logged as attempts of one entry.
- A message rejected by a `MessageEvent` listener (`reject()`) is not sent; an
  entry already created for it stays `queued`.
- Recipients are the To, Cc and Bcc headers; an SMTP envelope with other
  recipients (`envelope.recipients`) is not logged, and a resend goes to the
  header recipients. The stored copy is taken before S/MIME encryption.

### Showing the log

The bundle ships no log screen. Build yours on `SystemEmailLogRepository`:

```php
use Hexis\SystemEmailBundle\Enum\EmailLogStatus;
use Hexis\SystemEmailBundle\Repository\SystemEmailLogRepository;

$page = $repository->search(
    status: EmailLogStatus::FAILED,  // all filters optional
    type: 'booking',
    recipient: 'example.com',        // part of a To, Cc or Bcc address
    since: new \DateTimeImmutable('-7 days'),  // inclusive
    until: new \DateTimeImmutable(),           // exclusive
    page: 1,
    perPage: 50,
);                                   // Doctrine Paginator, newest first

count($page);                        // total matches
foreach ($page as $entry) { /* SystemEmailLog */ }

$entry->getStoredMessage();          // Email: getHtmlBody(), getAttachments(), getHeaders()
$repository->findResends($entry);    // entries that resend this one
```

The log holds message bodies and attachments, which are usually personal data:
restrict the screen and the commands to administrators. The stored message is
PHP-serialized, as Messenger's Doctrine transport stores it; an attachment
added from a file path (`attachFromPath()`) is stored as the path, so a resend
needs the file to still exist.

### Resend

A resend is a new entry linked to the original (`getResentFrom()`); the original
is left as it is. It goes through `MailerInterface`, so it is queued when your
Messenger routing queues `SendEmailMessage`.

```php
use Hexis\SystemEmailBundle\Service\EmailResender;

$resend = $resender->resend($entry);                           // original recipients
$resend = $resender->resend($entry, ['fixed@example.com']);    // corrected: replaces To, drops Cc and Bcc
$resend->getStatus();  // queued, or sent / failed when sent in-request (a transport error is logged, not thrown)
```

```bash
bin/console system-email:log:resend 42
bin/console system-email:log:resend 42 --to=fixed@example.com --to=other@example.com
```

### Retention

Nothing is deleted unless you purge, so retention is indefinite by default.
Schedule the purge to apply a retention period:

```bash
bin/console system-email:log:purge --older-than=365   # days
```

## Configuration reference

| Key | Default | Purpose |
|-----|---------|---------|
| `from.address` / `from.name` | `null` | Default sender when `send()` gets no override. |
| `async` | `true` | Dispatch via Messenger when a bus exists. |
| `default_type_enabled` | `true` | Enabled value for types created by sync. |
| `cache.enabled` / `cache.pool` | `true` / `cache.app` | PSR-6 caching of resolved recipients. |
| `admin.route_prefix` | `/admin/system-emails` | Where you mount the routes. |
| `admin.role` | `ROLE_ADMIN` | Role required for the UI. |
| `admin.base_template` | `@SystemEmail/layout.html.twig` | Layout the views extend (must define a `body` block). |
| `translation_domain` | `system_email` | Domain for admin strings. |
| `mail_log.enabled` | `false` | Log outgoing e-mail (see above). |
| `mail_log.table_name` | `system_email_log` | Table of the log entity. |
| `types` | `[]` | The email-type registry. |

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

MIT.
