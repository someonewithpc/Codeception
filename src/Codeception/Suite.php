<?php

declare(strict_types=1);

namespace Codeception;

use Codeception\Event\FailEvent;
use Codeception\Event\SuiteEvent;
use Codeception\Event\TestEvent;
use Codeception\Test\Descriptor;
use Codeception\Test\Interfaces\Dependent;
use PHPUnit\Framework\IncompleteTestError;
use PHPUnit\Framework\SelfDescribing;
use PHPUnit\Framework\SkippedDueToErrorInHookMethodException;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\SyntheticError;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestResult;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Metadata\Api\HookMethods;
use ReflectionClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Throwable;

class Suite extends TestSuite
{
    protected array $modules = [];

    protected ?string $baseName = null;

    private EventDispatcher $dispatcher;

    public function __construct(EventDispatcher $eventDispatcher)
    {
        $this->dispatcher = $eventDispatcher;
        parent::__construct('', '');
    }

    public function run(TestResult $result): void
    {
        if (count($this) === 0) {
            return;
        }

        /** @psalm-var class-string $className */
        $className   = $this->name;
        $hookMethods = (new HookMethods)->hookMethods($className);

        $result->startTestSuite($this);

        $this->dispatcher->dispatch(new SuiteEvent($this), 'suite.start');

        $test = null;

        if (class_exists($this->name, false)) {
            try {
                foreach ($hookMethods['beforeClass'] as $beforeClassMethod) {
                    if ($this->methodDoesNotExistOrIsDeclaredInTestCase($beforeClassMethod)) {
                        continue;
                    }

                    call_user_func([$this->name, $beforeClassMethod]);
                }
            } catch (Throwable $t) {
                $errorAdded = false;

                foreach ($this->tests() as $test) {
                    if ($result->shouldStop()) {
                        break;
                    }

                    $result->startTest($test);

                    if (!$errorAdded) {
                        $result->addError($test, $t, 0);

                        $errorAdded = true;
                    } else {
                        $result->addFailure(
                            $test,
                            new SkippedDueToErrorInHookMethodException,
                            0
                        );
                    }

                    $result->endTest($test, 0);
                }

                $this->dispatcher->dispatch(new SuiteEvent($this), 'suite.end');
                $result->endTestSuite($this);

                return;
            }
        }

        foreach ($this as $test) {
            if ($result->shouldStop()) {
                break;
            }
            $this->dispatcher->dispatch(new TestEvent($test), Events::TEST_START);

            if ($test instanceof TestInterface) {
                if ($test->getMetadata()->isBlocked()) {
                    // TODO: add to skipped?
                    continue;
                }

                try {
                    $this->fire(Events::TEST_BEFORE, new TestEvent($test));
                } catch (IncompleteTestError $e) {
                    $test->getTestResultObject()->addFailure($test, $e, 0);
                    $this->fire(Events::TEST_FAIL, new FailEvent($test, 0, $e));
                } catch (SkippedTest $e) {
                    $test->getTestResultObject()->addFailure($test, $e, 0);
                    $this->fire(Events::TEST_FAIL, new FailEvent($test, 0, $e));
                } catch (\Throwable $e) {
                    $test->getTestResultObject()->addError($test, $e, 0);
                    $this->fire(Events::TEST_ERROR, new FailEvent($test, 0, $e));
                }
            }
            $startTime = microtime(true);
            // TODO: handle failures
            $test->run($result);

            $duration = microtime(true) - $startTime;

            $this->fire(Events::TEST_SUCCESS, new TestEvent($test, $duration));
            $this->fire(Events::TEST_AFTER, new TestEvent($test, $duration));
            $this->dispatcher->dispatch(new TestEvent($test, $duration),  Events::TEST_END);
        }


        if (class_exists($this->name, false)) {
            foreach ($hookMethods['afterClass'] as $afterClassMethod) {
                if ($this->methodDoesNotExistOrIsDeclaredInTestCase($afterClassMethod)) {
                    continue;
                }

                call_user_func([$this->name, $afterClassMethod]);
            }
        }

        $result->endTestSuite($this);
    }

    public function reorderDependencies(): void
    {
        $tests = [];
        foreach (parent::tests() as $test) {
            $tests = array_merge($tests, $this->getDependencies($test));
        }

        $queue = [];
        $hashes = [];
        foreach ($tests as $test) {
            if (in_array(spl_object_hash($test), $hashes, true)) {
                continue;
            }
            $hashes[] = spl_object_hash($test);
            $queue[] = $test;
        }
        $this->setTests($queue);
    }

    /**
     * @param Dependent|SelfDescribing $test
     */
    protected function getDependencies($test): array
    {
        if (!$test instanceof Dependent) {
            return [$test];
        }
        $tests = [];
        foreach ($test->fetchDependencies() as $requiredTestName) {
            $required = $this->findMatchedTest($requiredTestName);
            if ($required === null) {
                continue;
            }
            $tests = array_merge($tests, $this->getDependencies($required));
        }
        $tests[] = $test;
        return $tests;
    }

    protected function findMatchedTest(string $testSignature): ?SelfDescribing
    {
        /** @var SelfDescribing $test */
        foreach (parent::tests() as $test) {
            $signature = Descriptor::getTestSignature($test);
            if ($signature === $testSignature) {
                return $test;
            }
        }

        return null;
    }

    public function getModules(): array
    {
        return $this->modules;
    }

    public function setModules(array $modules): void
    {
        $this->modules = $modules;
    }

    public function getBaseName(): string
    {
        return $this->baseName;
    }

    public function setBaseName(string $baseName): void
    {
        $this->baseName = $baseName;
    }


    private function methodDoesNotExistOrIsDeclaredInTestCase(string $methodName): bool
    {
        $reflector = new ReflectionClass($this->name);

        return !$reflector->hasMethod($methodName) ||
            $reflector->getMethod($methodName)->getDeclaringClass()->getName() === TestCase::class;
    }

    protected function fire(string $eventType, TestEvent $event): void
    {
        $test = $event->getTest();
        if ($test instanceof TestInterface) {
            foreach ($test->getMetadata()->getGroups() as $group) {
                $this->dispatcher->dispatch($event, $eventType . '.' . $group);
            }
        }
        $this->dispatcher->dispatch($event, $eventType);
    }
}
