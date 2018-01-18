<?php
/**
 * Project ArrowWorker
 * User:   louis
 * Date:   17-12-16
 */

$result = posix_kill(15022,SIGALRM);
var_dump($result);