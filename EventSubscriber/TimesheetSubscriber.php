<?php

namespace KimaiPlugin\CalDavSyncBundle\EventSubscriber;

use App\Event\AbstractTimesheetEvent;
use App\Event\AbstractTimesheetMultipleEvent;
use App\Event\TimesheetCreatePostEvent;
use App\Event\TimesheetDeleteMultiplePreEvent;
use App\Event\TimesheetDeletePreEvent;
use App\Event\TimesheetDuplicatePostEvent;
use App\Event\TimesheetRestartPostEvent;
use App\Event\TimesheetStopPostEvent;
use App\Event\TimesheetUpdateMultiplePostEvent;
use App\Event\TimesheetUpdatePostEvent;
use KimaiPlugin\CalDavSyncBundle\Sync\CalendarSync;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Notes every timesheet that Kimai creates, changes, stops, duplicates, restarts or deletes (web pages, API and console
 * alike) and writes them to the calendar once the request or command is over.
 * Deletions are noted before they happen, while the timesheet still has its id.
 */
final class TimesheetSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly CalendarSync $sync)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TimesheetCreatePostEvent::class => 'onTimesheet',
            TimesheetUpdatePostEvent::class => 'onTimesheet',
            TimesheetStopPostEvent::class => 'onTimesheet',
            TimesheetDuplicatePostEvent::class => 'onTimesheet',
            TimesheetRestartPostEvent::class => 'onTimesheet',
            TimesheetDeletePreEvent::class => 'onTimesheet',
            TimesheetUpdateMultiplePostEvent::class => 'onTimesheets',
            TimesheetDeleteMultiplePreEvent::class => 'onTimesheets',
            KernelEvents::TERMINATE => 'flush',
            ConsoleEvents::TERMINATE => 'flush',
        ];
    }

    public function onTimesheet(AbstractTimesheetEvent $event): void
    {
        $this->sync->touch($event->getTimesheet());
    }

    public function onTimesheets(AbstractTimesheetMultipleEvent $event): void
    {
        foreach ($event->getTimesheets() as $timesheet) {
            $this->sync->touch($timesheet);
        }
    }

    public function flush(): void
    {
        $this->sync->flush();
    }
}
