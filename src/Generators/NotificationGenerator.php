<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;

/**
 * Generates multi-channel notification classes per model lifecycle event.
 *
 * Per model per action produces:
 *   App\Notifications\{Model}{Action}Notification — mail + database channels
 *   resources/views/emails/{model}/{action}.blade.php — Markdown mail template
 *
 * Register in the model observer or the event listener.
 */
class NotificationGenerator
{
    public function generateNotification(string $modelName, string $action): string
    {
        $notifName = $modelName . Str::studly($action) . 'Notification';
        $variable  = Str::camel($modelName);
        $subject   = Str::headline($modelName) . ' ' . Str::headline($action);
        $view      = Str::kebab(Str::plural($modelName)) . '.' . $action;

        return <<<PHP
<?php

namespace App\Notifications;

use App\Models\\{$modelName};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class {$notifName} extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly {$modelName} \${$variable}) {}

    /** Which channels to send on. */
    public function via(object \$notifiable): array
    {
        return ['mail', 'database'];
    }

    // ── Mail ──────────────────────────────────────────────────────────────────

    public function toMail(object \$notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('{$subject}')
            ->markdown('emails.{$view}', [
                '{$variable}' => \$this->{$variable},
                'user'        => \$notifiable,
            ]);
    }

    // ── Database ──────────────────────────────────────────────────────────────

    public function toDatabase(object \$notifiable): array
    {
        return [
            'type'       => '{$modelName}.{$action}',
            'message'    => '{$subject}',
            'model_id'   => \$this->{$variable}->id,
            'model_type' => get_class(\$this->{$variable}),
            'url'        => null, // Set to a relevant URL if needed
        ];
    }

    public function toArray(object \$notifiable): array
    {
        return \$this->toDatabase(\$notifiable);
    }
}
PHP;
    }

    public function generateMarkdownMail(string $modelName, string $action): string
    {
        $title    = Str::headline($modelName) . ' ' . Str::headline($action);
        $variable = Str::camel($modelName);
        $headline = Str::headline($modelName) . ' ' . Str::headline($action);

        return <<<BLADE
@component('mail::message')
# {$headline}

Hello {{ \$user->name }},

Your **{$modelName}** has been **{$action}**.

@component('mail::panel')
**ID:** {{ \${$variable}->id }}
@endcomponent

@component('mail::button', ['url' => config('app.url')])
View Details
@endcomponent

Thanks,
{{ config('app.name') }}
@endcomponent
BLADE;
    }

    public function generateObserverNotifications(string $modelName, array $actions = ['created','updated','deleted']): string
    {
        $variable = Str::camel($modelName);
        $methods  = '';

        foreach ($actions as $action) {
            $notifName = $modelName . Str::studly($action) . 'Notification';
            $methods  .= <<<PHP

    public function {$action}({$modelName} \${$variable}): void
    {
        // Notify the model's owner/related users as needed
        // \${$variable}->user?->notify(new \\App\\Notifications\\{$notifName}(\${$variable}));
    }

PHP;
        }

        return <<<PHP
<?php

namespace App\Observers;

use App\Models\\{$modelName};

class {$modelName}NotificationObserver
{
{$methods}}
PHP;
    }
}
