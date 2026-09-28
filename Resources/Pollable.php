<?php

namespace Voyager\IOPools\Resources;

use Voyager\Contracts\IOPools\LoopResources\Tickable;
use Voyager\IOPools\LoopResource;

abstract class Pollable extends LoopResource implements Tickable
{

}