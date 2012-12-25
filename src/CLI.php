<?php

/**
 * Copyright (c) 2009-2012 Arne Blankerts <arne@blankerts.de>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 *
 *   * Redistributions of source code must retain the above copyright notice,
 *     this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright notice,
 *     this list of conditions and the following disclaimer in the documentation
 *     and/or other materials provided with the distribution.
 *
 *   * Neither the name of Arne Blankerts nor the names of contributors
 *     may be used to endorse or promote products derived from this software
 *     without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT  * NOT LIMITED TO,
 * THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR
 * PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER ORCONTRIBUTORS
 * BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @package    Autoload
 * @author     Arne Blankerts <arne@blankerts.de>
 * @copyright  Arne Blankerts <arne@blankerts.de>, All rights reserved.
 * @license    BSD License
 */

namespace TheSeer\Autoload {

    use TheSeer\DirectoryScanner\DirectoryScanner;

    /**
     * CLI interface to AutoloadBuilder / StaticBuilder
     *
     * @author     Arne Blankerts <arne@blankerts.de>
     * @copyright  Arne Blankerts <arne@blankerts.de>, All rights reserved.
     */
    class CLI {

        /**
         * @var Factory
         */
        private $factory;

        public function __construct(Factory $factory) {
            $this->factory = $factory;
        }

        /**
         * Main executor method
         *
         * @return void
         */
        public function run() {

            try {
                $input = $this->setupInput();
                $input->process();
            } catch (\ezcConsoleException $e) {
                $this->showVersion();
                echo $e->getMessage() . "\n\n";
                $this->showUsage();
                exit(3);
            }

            if ($this->helpOption->value === TRUE) {
                $this->showVersion();
                $this->showUsage();
                exit(0);
            }

            if ($this->versionOption->value === TRUE ) {
                $this->showVersion();
                exit(0);
            }

            $this->beQuiet = $input->getOption('quiet')->value;

            try {

                if ($this->pharOption->value !== FALSE) {
                    $keyfile = $input->getOption('key')->value;
                    if ($keyfile != '') {
                        if (!extension_loaded('openssl')) {
                            $this->message("Extension for OpenSSL not loaded - cannot sign phar archive - process aborted.\n\n", STDERR);
                            exit(1);
                        }
                        $keydata = file_get_contents($keyfile);
                        if (strpos($keydata, 'ENCRYPTED')!==FALSE) {
                            $this->beQuiet = FALSE;
                            $this->message("Passphrase for key '$keyfile': ");
                            $g = shell_exec('stty -g');
                            shell_exec('stty -echo');
                            $passphrase = trim(fgets(STDIN));
                            $this->message("\n");
                            shell_exec('stty ' . $g);
                            $private = openssl_pkey_get_private($keydata, $passphrase);
                        } else {
                            $private = openssl_pkey_get_private($keydata);
                        }
                        if (!$private) {
                            $this->message("Opening private key '$keyfile' failed - process aborted.\n\n", STDERR);
                            exit(1);
                        }
                        $keyDetails = openssl_pkey_get_details($private);
                        $privateKey = '';
                        openssl_pkey_export($private, $privateKey);
                        file_put_contents($this->outputOption->value . '.pubkey', $keyDetails['key']);
                    }
                    if (file_exists($this->outputOption->value)) {
                        unlink($this->outputOption->value);
                    }
                    $phar = new \Phar($input->getOption('output')->value, 0, basename($input->getOption('output')->value));
                    $phar->startBuffering();
                    if ($privateKey) {
                        $phar->setSignatureAlgorithm(\Phar::OPENSSL, $privateKey);
                    }

                }

                $found = 0;
                $withMimeCheck = $input->getOption('paranoid')->value || !$input->getOption('trusting')->value;
                $basedir = $input->getOption('basedir')->value;

                $finder = new ClassFinder(
                    $input->getOption('static')->value,
                    $input->getOption('tolerant')->value,
                    $input->getOption('nolower')->value
                );

                if (!$this->beQuiet) {
                    $this->showVersion();
                }

                foreach($input->getArguments() as $directory) {
                    $this->message('Scanning directory ' . $directory . "\n");
                    if ($basedir == NULL) {
                        $basedir = $directory;
                    }
                    $scanner = $this->factory->getScanner($directory, $input);
                    if ($this->pharOption->value !== FALSE) {
                        $pharScanner = $input->getOption('all')->value ? $this->factory->getScanner($directory, $input, FALSE) : $scanner;
                        $phar->buildFromIterator($pharScanner, $basedir);
                        $scanner->rewind();
                    }

                    $found  += $finder->parseMulti($scanner, $withMimeCheck);
                    // this unset is needed to "fix" a segfault on shutdown in some PHP Versions
                    unset($scanner);
                }

                if ($found == 0) {
                    $this->message("No classes were found - process aborted.\n\n", STDERR);
                    exit(1);
                }

                $builder = $this->factory->getBuilder($finder, $input);

                if ($this->lintOption->value === TRUE) {
                    exit( $this->lintCode($builder->render(), $input) ? 0 : 4);
                }

                if ($this->outputOption->value == 'STDOUT') {
                    echo "\n" . $builder->render() . "\n\n";
                } else {
                    if ($this->pharOption->value !== FALSE) {
                        $builder->setVariable('PHAR', basename($this->outputOption->value));
                        $stub = $builder->render();
                        if (strpos($stub, '__HALT_COMPILER();')===FALSE) {
                            $this->message(
                                "Warning: Template used in phar mode did not contain required __HALT_COMPILER() call\n" .
                                "which has been added automatically. The used stub code may not work as intended.\n\n", STDERR);
                            $stub .= $builder->getLineBreak() . '__HALT_COMPILER();';
                        }
                        $phar->setStub($stub);
                        if ($input->getOption('gzip')->value) {
                            $phar->compressFiles(\Phar::GZ);
                        } elseif ($input->getOption('bzip2')->value) {
                            $phar->compressFiles(\Phar::BZ2);
                        }
                        $phar->stopBuffering();
                        $this->message( "\nphar archive '{$this->outputOption->value}' generated.\n\n");
                    } else {
                        $builder->save($this->outputOption->value);
                        $this->message( "\nAutoload file '{$this->outputOption->value}' generated.\n\n");
                    }
                }
                exit(0);

            } catch (\Exception $e) {
                $this->showVersion();
                $this->message("Error while processing request:\n - " . $e->getMessage()."\n", STDERR);
                exit(1);
            }
        }

        protected function message($msg, $target = STDOUT) {
            if ($this->beQuiet) return;
            fwrite($target, $msg);
        }

        /**
         * Helper to output version information
         */
        protected function showVersion() {
            echo Version::getInfoString() . "\n\n";
        }

        /**
         * Helper to output usage information
         */
        protected function showUsage() {
            print <<<EOF
Usage: phpab [switches] <directory1> [...<directoryN>]

  -i, --include       File pattern to include (default: *.php)
  -e, --exclude       File pattern to exclude

  -b, --basedir       Basedir for filepaths
  -t, --template      Path to code template to use

  -o, --output        Output file for generated code (default: STDOUT)
  -p, --phar          Create a phar archive (requires -o )
      --bzip2         Compress phar archive using bzip2 (requires -p) (bzip2 required)
      --gz            Compress phar archive using gzip (requires -p) (gzip required)
      --key           OpenSSL key file to use for signing phar archive (requires -p) (openssl required)

  -c, --compat        Generate PHP 5.2 compatible code
  -s, --static        Generate a static require file

  -n, --nolower       Do not lowercase classnames for case insensitivity

  -q, --quiet         Quiet mode, do not output any processing errors or information

      --format        Dateformat string for timestamp
      --linebreak     Linebreak style (CR, CRLF or LF, default: LF)
      --indent        String used for indenting or number of spaces (default: 16 (compat 12) spaces)

      --tolerant      Ignore Class Redeclarations in the same file
      --once          Use require_once instead of require when creating a static require file

      --all           Include all files in given directory when creating a phar

      --trusting      Do not check mimetype of files prior to parsing (default)
      --paranoid      Do check mimetype of files prior to parsing

      --var name=foo  Assign value 'foo' to variable 'name' to be used in (custom) templates

      --lint          Run lint on generated code and exit
      --lint-php      PHP binary to use for linting (default: /usr/bin/php or c:\php\php.exe)

  -h, --help          Prints this usage information
  -v, --version       Prints the version and exits

EOF;
        }

        /**
         * @return \ezcConsoleInput
         */
        protected function setupInput() {
            $input = new \ezcConsoleInput();

            $this->versionOption = $input->registerOption( new \ezcConsoleOption( 'v', 'version' ) );
            $this->versionOption->shorthelp    = 'Prints the version and exits';
            $this->versionOption->isHelpOption = TRUE;

            $this->helpOption = $input->registerOption( new \ezcConsoleOption( 'h', 'help' ) );
            $this->helpOption->isHelpOption = TRUE;
            $this->helpOption->shorthelp    = 'Prints this usage information';

            $this->outputOption = $input->registerOption( new \ezcConsoleOption(
                'o', 'output', \ezcConsoleInput::TYPE_STRING, 'STDOUT', FALSE,
                'Output file for generated code (default: STDOUT)'
            ));

            $this->pharOption = $input->registerOption( new \ezcConsoleOption(
                'p', 'phar', \ezcConsoleInput::TYPE_NONE, NULL, FALSE,
                'Build a phar archive of directory contents',
                NULL,
                array( new \ezcConsoleOptionRule( $input->getOption( 'o' ) ) )
            ));

            $input->registerOption( new \ezcConsoleOption(
                '', 'all', \ezcConsoleInput::TYPE_NONE, NULL, FALSE,
                'Add all files from src dir to phar',
                NULL,
                array( new \ezcConsoleOptionRule( $input->getOption( 'p' ) ) )
            ));

            $bzip2 = $input->registerOption( new \ezcConsoleOption(
                '', 'bzip2', \ezcConsoleInput::TYPE_NONE, NULL, FALSE,
                'Compress files phar with bzip2',
                NULL,
                array( new \ezcConsoleOptionRule( $input->getOption( 'p' ) ) )
            ));

            $gzip = $input->registerOption( new \ezcConsoleOption(
                '', 'gzip', \ezcConsoleInput::TYPE_NONE, NULL, FALSE,
                'Compress files phar with gzip',
                NULL,
                array( new \ezcConsoleOptionRule( $input->getOption( 'p' ) ) ),
                array( new \ezcConsoleOptionRule( $bzip2 ) )
            ));
            $bzip2->addExclusion(new \ezcConsoleOptionRule($gzip));

            $input->registerOption( new \ezcConsoleOption(
                '', 'key', \ezcConsoleInput::TYPE_STRING, NULL, FALSE,
                'Keyfile to use for signing phar archive',
                NULL,
                array( new \ezcConsoleOptionRule( $input->getOption( 'p' ) ) )
            ));

            $input->registerOption( new \ezcConsoleOption(
                'i', 'include', \ezcConsoleInput::TYPE_STRING, '*.php', TRUE,
                'File pattern to include (default: *.php)'
            ));

            $input->registerOption( new \ezcConsoleOption(
                'e', 'exclude', \ezcConsoleInput::TYPE_STRING, NULL, TRUE,
                'File pattern to exclude'
            ));

            $input->registerOption( new \ezcConsoleOption(
                'b', 'basedir', \ezcConsoleInput::TYPE_STRING, NULL, FALSE,
                'Basedir for filepaths'
            ));

            $input->registerOption( new \ezcConsoleOption(
                't', 'template', \ezcConsoleInput::TYPE_STRING, NULL, FALSE,
                'Path to code template to use'
            ));

            $input->registerOption( new \ezcConsoleOption(
                '', 'format', \ezcConsoleInput::TYPE_STRING, NULL, FALSE,
                'Dateformat string for timestamp'
            ));

            $input->registerOption( new \ezcConsoleOption(
                '', 'linebreak', \ezcConsoleInput::TYPE_STRING, NULL, FALSE,
                'Linebreak style (CR, CR/LF or LF)'
            ));

            $input->registerOption( new \ezcConsoleOption(
                '', 'indent', \ezcConsoleInput::TYPE_STRING, NULL, FALSE,
                'String used for indenting (default: 3 spaces)'
            ));

            $this->lintOption = $input->registerOption( new \ezcConsoleOption(
                '', 'lint', \ezcConsoleInput::TYPE_NONE, NULL, FALSE,
                'Run lint on generated code'
            ));

            $input->registerOption( new \ezcConsoleOption(
                '', 'lint-php', \ezcConsoleInput::TYPE_STRING, NULL, FALSE,
                'PHP binary path for linting (default: /usr/bin/php or c:\\php\\php.exe)',
                NULL,
                array( new \ezcConsoleOptionRule( $input->getOption( 'lint' ) ) )
            ));

            $input->registerOption( new \ezcConsoleOption(
                'c', 'compat', \ezcConsoleInput::TYPE_NONE, NULL, FALSE,
                'Generate PHP 5.2 compliant code'
            ));

            $this->staticOption = $input->registerOption( new \ezcConsoleOption(
                's', 'static', \ezcConsoleInput::TYPE_NONE, NULL, FALSE,
                'Build a static require file'
            ));

            $input->registerOption( new \ezcConsoleOption(
                '', 'tolerant', \ezcConsoleInput::TYPE_NONE, NULL, FALSE,
                'Ignore Class Redeclarations in the same file'
            ));

            $trusting = $input->registerOption( new \ezcConsoleOption(
                '', 'trusting', \ezcConsoleInput::TYPE_NONE, TRUE, FALSE,
                'Do not check mimetype of files prior to parsing'
            ));
            $paranoid = $input->registerOption( new \ezcConsoleOption(
                '', 'paranoid', \ezcConsoleInput::TYPE_NONE, FALSE, FALSE,
                'Do check mimetype of files prior to parsing',
                NULL,
                array(),
                array( new \ezcConsoleOptionRule($trusting) )
            ));
            $trusting->addExclusion(new \ezcConsoleOptionRule($paranoid));

            $this->onceOption = $input->registerOption( new \ezcConsoleOption(
                '', 'once', \ezcConsoleInput::TYPE_NONE, NULL, FALSE,
                'Use require_once in static require mode',
                NULL,
                array( new \ezcConsoleOptionRule( $input->getOption( 's' ) ) )
            ));

            $input->registerOption( new \ezcConsoleOption(
                'n', 'nolower', \ezcConsoleInput::TYPE_NONE, NULL, FALSE,
                'Do not lowercase classnames for case insensitivity'
            ));

            $input->registerOption( new \ezcConsoleOption(
                'q', 'quiet', \ezcConsoleInput::TYPE_NONE, NULL, FALSE,
                'Run in quiet mode, no output'
            ));

            $input->registerOption( new \ezcConsoleOption(
                NULL, 'var', \ezcConsoleInput::TYPE_STRING, array(), TRUE,
                'Assign variable'
            ));

            $input->argumentDefinition = new \ezcConsoleArguments();
            $input->argumentDefinition[0] = new \ezcConsoleArgument( "directory" );
            $input->argumentDefinition[0]->shorthelp = "The directory to process.";
            $input->argumentDefinition[0]->multiple = TRUE;

            return $input;
        }
    }
}

