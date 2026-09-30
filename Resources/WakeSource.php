<?php

namespace Voyager\IOPools\Resources;

use Voyager\Contracts\IOPools\LoopResources\Sleepable;
use Voyager\Contracts\IOPools\LoopResources\Wakeable;
use Voyager\IOPools\LoopResource;

abstract class WakeSource extends LoopResource implements Wakeable
{

}