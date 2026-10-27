<?php

namespace KimaiPlugin\CalDavSyncBundle;

use App\Plugin\PluginInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Copies every finished timesheet into a CalDAV calendar, one way (see README.md).
 */
class CalDavSyncBundle extends Bundle implements PluginInterface
{
}
