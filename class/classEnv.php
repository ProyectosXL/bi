<?php


class DotEnv
{
    /**
     * The directory where the .env file can be located.
     *
     * @var string
     */
    protected $path;


    public function __construct(string $path)
    {
        if(!file_exists($path)) {
            throw new \InvalidArgumentException(sprintf('%s does not exist', $path));
        }
        $this->path = $path;
    }

    private function load() :void
    {
        if (!is_readable($this->path)) {
            throw new \RuntimeException(sprintf('%s file is not readable', $this->path));
        }

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {

            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }

    public function listVars(){
        (new DotEnv(__DIR__ . '/../../.env'))->load();

        $vars = array(

            'HOST_CENTRAL' => getenv('HOST_CENTRAL'),
            'HOST_LOCALES' => getenv('HOST_LOCALES'),
            'DATABASE_CENTRAL' => getenv('DATABASE_CENTRAL'),
            'DATABASE_LOCALES' => getenv('DATABASE_LOCALES'),
            'DATABASE_UY' => getenv('DATABASE_UY'),
            'DATABASE_TANGOBIS' => getenv('DATABASE_TANGOBIS'),
            'DATABASE_SUC_UY' => getenv('DATABASE_SUC_UY'),
            'HOST_APPS' => getenv('HOST_APPS'),
            'DATABASE_APPS' => getenv('DATABASE_APPS'),
            'DATABASE_POWER_FRANQUICIAS' => getenv('DATABASE_POWER_FRANQUICIAS'),
            'DATABASE_POWER' => getenv('DATABASE_POWER'),
            'DATABASE_POWER_UY' => getenv('DATABASE_POWER_UY'),
            'USER' => getenv('USER'),
            'PASS' => getenv('PASS'),
            'PASS_LOCALES' => getenv('PASS_LOCALES'),
            'CHARACTER' => getenv('CHARACTER'),
            'ENV' => getenv('ENV'),
            'HOST_EMAIL' => getenv('HOST_EMAIL'),
            'USER_EMAIL' => getenv('USER_EMAIL'),
            'PASS_EMAIL' => getenv('PASS_EMAIL'),
            

        );

        return $vars;


    }

}