<?php

namespace SystemX\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

// Scaffold a system-x App class into the HOST app (Laravel make:-convention: generate + instruct,
// never auto-register). Writes app/SystemX/{Name}App.php with a runnable stub, then prints the
// one-line registration snippet. See the shipping-a-system-x-app skill.
class MakeAppCommand extends Command
{
    protected $signature = 'system-x:make-app {name : The app class base name, e.g. Todo} {--namespace=App\\SystemX : The namespace/dir to generate into}';

    protected $description = 'Scaffold a system-x App class in the host application.';

    public function handle(): int
    {
        $base = Str::studly($this->argument('name'));
        $class = "{$base}App";
        $namespace = trim($this->option('namespace'), '\\');
        $slug = Str::kebab($base);

        // Resolve the path from the namespace: leading App\ -> app/ (anchored, so a pathological
        // App\Foo\App\Bar doesn't double-replace), then normalise separators.
        $relative = preg_replace('/^App\\\\/', 'app/', $namespace);
        $relative = str_replace('\\', '/', $relative);
        $dir = base_path($relative);
        $path = "{$dir}/{$class}.php";

        if (file_exists($path)) {
            $this->error("{$path} already exists.");

            return self::FAILURE;
        }

        @mkdir($dir, 0755, true);
        file_put_contents($path, $this->stub($namespace, $class, $slug));

        $this->info("Created {$path}");
        $this->line('');
        $this->line("Register it in a service provider's boot() (boot, not register -- so the AppRegistry singleton is bound):");
        $this->line("    \$this->app->make(\\SystemX\\Core\\Runtime\\AppRegistry::class)->register('{$slug}', \\{$namespace}\\{$class}::class);");

        return self::SUCCESS;
    }

    private function stub(string $namespace, string $class, string $slug): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use SystemX\Core\Runtime\App;
use SystemX\Core\Widgets\Button;
use SystemX\Core\Widgets\Label;
use SystemX\Core\Widgets\Stack;
use SystemX\Core\Widgets\Window;
use SystemX\Core\Wire\Node;

class {$class} extends App
{
    public int \$count = 0;

    public function slug(): string
    {
        return '{$slug}';
    }

    public function render(): Node
    {
        return Window::make('{$class}')->size(320, 200)->content([
            Stack::make()->content([
                Label::make("Clicked {\$this->count} times")->id('count'),
                Button::make('Click me')->id('go')->handles('go'),
            ]),
        ]);
    }

    public function go(): void
    {
        \$this->count++;
    }
}

PHP;
    }
}
