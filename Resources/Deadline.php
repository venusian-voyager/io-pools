<?php

namespace Voyager\IOPools\Resources;

use Voyager\Contracts\IOPools\LoopResources\Deadlined;
use Voyager\IOPools\LoopResource;

abstract class Deadline extends LoopResource implements Deadlined
{

}