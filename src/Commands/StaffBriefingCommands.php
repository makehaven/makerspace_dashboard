<?php

declare(strict_types=1);

namespace Drupal\makerspace_dashboard\Commands;

use Drupal\makerspace_dashboard\Service\StaffBriefingService;
use Drush\Commands\DrushCommands;

/**
 * Preview or send the Monday staff briefing.
 */
class StaffBriefingCommands extends DrushCommands {

  public function __construct(protected StaffBriefingService $briefing) {
    parent::__construct();
  }

  /**
   * Shows (or sends) the staff briefing for a week.
   *
   * Without --send-to nothing is sent. The Monday cron send is controlled
   * separately by the state key makerspace_dashboard.staff_briefing.recipients.
   *
   * @command makerspace-dashboard:staff-briefing
   * @aliases msd:briefing
   * @option week-ending The Monday the week ends on (Y-m-d); the most recent Monday when left out.
   * @option send-to Comma-separated emails to send this briefing to now.
   * @option html Write the email HTML to this file.
   * @usage drush msd:briefing
   *   Print last week's briefing as text.
   * @usage drush msd:briefing --html=/tmp/briefing.html
   *   Write the email for a browser preview.
   * @usage drush msd:briefing --send-to=jrlogan@makehaven.org
   *   Send it to one person now.
   */
  public function show(array $options = ['week-ending' => NULL, 'send-to' => NULL, 'html' => NULL]): void {
    $weekEnd = NULL;
    if (!empty($options['week-ending'])) {
      $weekEnd = new \DateTimeImmutable($options['week-ending'] . ' 00:00', $this->briefing->timezone());
      if ($weekEnd->format('N') !== '1') {
        throw new \InvalidArgumentException('--week-ending must be a Monday.');
      }
    }
    $data = $this->briefing->build($weekEnd);

    $this->output()->writeln(sprintf('Staff briefing: %s to %s', $data['week_start']->format('D M j'), $data['week_end']->modify('-1 day')->format('D M j, Y')));
    $this->output()->writeln("\nWORTH DISCUSSING");
    foreach ($data['discuss'] ?: ['Nothing outside its normal range.'] as $item) {
      $this->output()->writeln('  • ' . $item);
    }
    foreach ($data['roles'] as $role) {
      $this->output()->writeln("\n" . strtoupper($role['title']));
      foreach ($role['lines'] as $line) {
        $this->output()->writeln(sprintf('  %-44s %8s  (%s %s, usual %s)%s', $line['title'], $line['value_text'], $line['stock'] ? 'change' : 'prev', $line['last_text'], $line['avg_text'], $line['flag'] ? '  ← ' . $line['flag'] : ''));
      }
      foreach ($role['lists'] as $list) {
        $this->output()->writeln('  ' . $list['title']);
        foreach ($list['items'] as $item) {
          $this->output()->writeln('    - ' . (is_array($item) ? $item['text'] : $item));
        }
      }
    }

    if (!empty($options['html'])) {
      file_put_contents($options['html'], $this->briefing->renderHtml($data));
      $this->logger()->success('HTML written to ' . $options['html']);
    }
    if (!empty($options['send-to'])) {
      $to = array_filter(array_map('trim', explode(',', $options['send-to'])), static fn ($m) => filter_var($m, FILTER_VALIDATE_EMAIL));
      $sent = $this->briefing->send($data, $to);
      $this->logger()->success(sprintf('Sent to %d of %d.', $sent, count($to)));
    }
    $recipients = $this->briefing->recipients();
    $this->output()->writeln("\nMonday cron send: " . ($recipients ? 'ON → ' . implode(', ', $recipients) : 'OFF (state key ' . StaffBriefingService::STATE_RECIPIENTS . ' is empty)'));
  }

}
