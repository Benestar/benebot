<?php

namespace BeneBot;


use Symfony\Component\Console\Command\Command;

interface WikibaseExecutor {

	public function configure( Command $command );

	public function execute( CommandHelper $commandHelper );

}
