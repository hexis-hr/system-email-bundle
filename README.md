# System Email Bundle

Make the recipients and the on/off switch of your application's transactional
emails configurable from a backend UI, without redeploying. You declare the
email **types** (the arbitrary functionalities that send mail) centrally in
YAML; admins manage **recipients** (TO/CC/BCC) and flip a per-type **send
kill-switch** in the browser. Delivery goes through Symfony Mailer.

The bundle owns the configuration and the recipient model. Message content
(subject and HTML body) stays in your application code - this bundle answers
"who receives this, and is it turned on?".

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
    types:
        sap_alert: { label: 'SAP Alert' }
        booking:   { label: 'Booking notifications' }
        no_reply:  { label: 'No-reply sender' }
```

`types` is the single source of truth for which email types exist. Add a key
here, redeploy, run the sync command, and it becomes manageable in the UI.

## Database schema

The bundle ships Doctrine-mapped entities (`system_email_type`,
`system_email_recipient`) and registers their mapping automatically. Generate a
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
otherwise it is sent in-request.

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
| `types` | `[]` | The email-type registry. |

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

MIT.
