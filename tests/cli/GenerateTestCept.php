<?php

$I = new CliGuy\GeneratorSteps($scenario);
$I->wantTo('generate sample Test');
$I->amInPath('tests/data/sandbox');
$I->executeCommand('generate:test dummy Sommy');
$I->seeFileWithGeneratedClass('SommyTest');
$I->seeInThisFile('class SommyTest extends \Codeception\Test\Unit');
if ((PHP_MAJOR_VERSION == 7) && (PHP_MINOR_VERSION < 4)) {
    $I->seeInThisFile('protected $tester');
} else {
    $I->seeInThisFile('protected DumbGuy $tester');
}
$I->seeInThisFile("function _before(");
