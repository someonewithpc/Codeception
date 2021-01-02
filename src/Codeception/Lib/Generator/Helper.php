<?php

declare(strict_types=1);

namespace Codeception\Lib\Generator;

use Codeception\Lib\Generator\Shared\Classname;
use Codeception\Util\Shared\Namespaces;
use Codeception\Util\Template;

class Helper
{
    use Namespaces;
    use Classname;

    protected string $template = <<<EOF
<?php

declare(strict_types=1);
{{namespace}}
// here you can define custom actions
// all public methods declared in helper class will be available in \$I

class {{name}} extends \\Codeception\\Module
{

}

EOF;

    protected string $namespace;

    protected string $name;

    private array $settings = [];

    public function __construct(array $settings, string $name)
    {
        $this->settings = $settings;
        $this->name = $name;
    }

    public function produce(): string
    {
        return (new Template($this->template))
            ->place('namespace', $this->getNamespaceHeader($this->supportNamespace() . 'Helper\\' . $this->name))
            ->place('name', $this->getShortClassName($this->name))
            ->produce();
    }
}
