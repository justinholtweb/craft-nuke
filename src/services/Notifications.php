<?php

namespace justinholtweb\nuke\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use justinholtweb\nuke\models\Settings;
use justinholtweb\nuke\models\StrikeOutcome;
use justinholtweb\nuke\models\SweepResult;
use justinholtweb\nuke\models\Target;
use justinholtweb\nuke\Plugin;

/**
 * Emails what happened.
 *
 * A deletion is the kind of event where the person who needs to know is often not the person who
 * did it. Notifications are a Pro feature, and off until switched on — a plugin that starts
 * emailing on install is a plugin that gets uninstalled.
 */
class Notifications extends Component
{
    /**
     * @param SweepResult[] $results
     */
    public function sweepFinished(int $runId, array $results): void
    {
        $settings = $this->settings();

        if (!$this->shouldSend()) {
            return;
        }

        $removed = array_sum(array_map(fn(SweepResult $r) => $r->removed, $results));
        $failed = count(array_filter($results, fn(SweepResult $r) => $r->failed));

        if (!$this->passesThreshold($settings, $removed > 0, $failed > 0)) {
            return;
        }

        $this->send(
            Craft::t('nuke', '[{site}] Sweep removed {n} items', [
                'site' => $this->siteName(),
                'n' => $removed,
            ]),
            'nuke/_email/sweep',
            [
                'run' => Plugin::getInstance()->runs->get($runId),
                'results' => $results,
                'removed' => $removed,
                'failed' => $failed,
            ],
        );
    }

    public function strikeFinished(int $runId, Target $target, StrikeOutcome $outcome): void
    {
        $settings = $this->settings();

        if (!$this->shouldSend() || !$settings->notifyOnStrike) {
            return;
        }

        if (!$this->passesThreshold($settings, $outcome->total() > 0, $outcome->failed > 0)) {
            return;
        }

        $this->send(
            Craft::t('nuke', '[{site}] Strike deleted {n} elements', [
                'site' => $this->siteName(),
                'n' => $outcome->elements,
            ]),
            'nuke/_email/strike',
            [
                'run' => Plugin::getInstance()->runs->get($runId),
                'target' => $target,
                'outcome' => $outcome,
            ],
        );
    }

    private function shouldSend(): bool
    {
        return Plugin::getInstance()->isPro()
            && $this->settings()->notifyEnabled
            && $this->settings()->resolvedRecipients() !== [];
    }

    private function passesThreshold(Settings $settings, bool $changedSomething, bool $hadProblems): bool
    {
        return match ($settings->notifyOn) {
            Settings::NOTIFY_ALWAYS => true,
            Settings::NOTIFY_PROBLEMS => $hadProblems,
            default => $changedSomething || $hadProblems,
        };
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function send(string $subject, string $template, array $variables): void
    {
        $view = Craft::$app->getView();
        $mailer = Craft::$app->getMailer();

        // Rendered in the control panel's template mode: these templates live in the plugin, not
        // in the site, and a site whose own templates happen to define `nuke/` should not be able
        // to change what an audit email says.
        $body = $view->renderTemplate($template, $variables, $view::TEMPLATE_MODE_CP);

        foreach ($this->settings()->resolvedRecipients() as $recipient) {
            $mailer
                ->compose()
                ->setTo($recipient)
                ->setSubject($subject)
                ->setHtmlBody($body)
                ->send();
        }
    }

    private function siteName(): string
    {
        return (string)App::parseEnv(Craft::$app->getSites()->getPrimarySite()->getName());
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
