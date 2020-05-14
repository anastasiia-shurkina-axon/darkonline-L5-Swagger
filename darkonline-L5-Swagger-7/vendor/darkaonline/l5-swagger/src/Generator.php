<?php

namespace L5Swagger;

use File;
use L5Swagger\Exceptions\L5SwaggerException;
use Symfony\Component\Yaml\Dumper as YamlDumper;

class Generator {

    /**
     * @var string
     */
    protected $appDir;

    /**
     * @var string
     */
    protected $docDir;

    /**
     * @var string
     */
    protected $docsFile;

    /**
     * @var string
     */
    protected $yamlDocsFile;

    /**
     * @var array
     */
    protected $excludedDirs;

    /**
     * @var array
     */
    protected $constants;

    /**
     * @var \OpenApi\Annotations\OpenApi
     */
    protected $swagger;

    /**
     * @var bool
     */
    protected $yamlCopyRequired;

    public function __construct() {
        $this->appDir = config('l5-swagger.paths.annotations');
        $this->docDir = config('l5-swagger.paths.docs');
        $this->docsFile = $this->docDir . '/' . config('l5-swagger.paths.docs_json', 'api-docs.json');
        $this->yamlDocsFile = $this->docDir . '/' . config('l5-swagger.paths.docs_yaml', 'api-docs.yaml');
        $this->excludedDirs = config('l5-swagger.paths.excludes');
        $this->constants = config('l5-swagger.constants') ?: [];
        $this->yamlCopyRequired = config('l5-swagger.generate_yaml_copy', false);
    }

    public static function generateDocs($group = '') {
        if (!$group && config('l5-swagger.api.separated_doc')) {
            foreach (config('l5-swagger.doc_groups', []) as $group => $groupConfig) {
                self::generateGroupDocs($group);
            }
        } else if ($group) {
            if (!config('l5-swagger.doc_groups.' . $group)) {
                throw new L5SwaggerException('Unknown documentation group: ' . $group);
            }
            self::generateGroupDocs($group);
        } else {
            self::generateGroupDocs();
        }
    }

    /**
     * Check directory structure and permissions.
     *
     * @return Generator
     */
    protected function prepareDirectory() {
        if (File::exists($this->docDir) && !is_writable($this->docDir)) {
            throw new L5SwaggerException('Documentation storage directory is not writable');
        }

// delete all existing documentation
        if (File::exists($this->docDir)) {
            File::deleteDirectory($this->docDir);
        }

        File::makeDirectory($this->docDir);

        return $this;
    }

   

    /**
     * Scan directory and create Swagger.
     *
     * @return Generator
     */
    protected function scanFilesForDocumentation() {
        if ($this->isOpenApi()) {
            $this->swagger = \OpenApi\scan(
                    $this->appDir, ['exclude' => $this->excludedDirs]
            );
        }

        if (!$this->isOpenApi()) {
            $this->swagger = \Swagger\scan(
                    $this->appDir, ['exclude' => $this->excludedDirs]
            );
        }

        return $this;
    }

    /**
     * Generate servers section or basePath depending on Swagger version.
     *
     * @return Generator
     */
    protected function populateServers() {
        if (config('l5-swagger.paths.base') !== null) {
            if ($this->isOpenApi()) {
                $this->swagger->servers = [
                    new \OpenApi\Annotations\Server(['url' => config('l5-swagger.paths.base')]),
                ];
            }

            if (!$this->isOpenApi()) {
                $this->swagger->basePath = config('l5-swagger.paths.base');
            }
        }

        return $this;
    }

    /**
     * Save documentation as json file.
     *
     * @return Generator
     */
    protected function saveJson() {
        $this->swagger->saveAs($this->docsFile);

        $security = new SecurityDefinitions();
        $security->generate($this->docsFile);

        return $this;
    }

    /**
     * Save documentation as yaml file.
     *
     * @return Generator
     */
    protected function makeYamlCopy() {
        if ($this->yamlCopyRequired) {
            file_put_contents(
                    $this->yamlDocsFile, (new YamlDumper(2))->dump(json_decode(file_get_contents($this->docsFile), true), 20)
            );
        }
    }

    /**
     * Check which documentation version is used.
     *
     * @return bool
     */
    protected function isOpenApi() {
        return version_compare(config('l5-swagger.swagger_version'), '3.0', '>=');
    }

    protected static function generateGroupDocs($group = '') {
        $appDir = config('l5-swagger.paths.annotations');
        $docDir = config('l5-swagger.paths.docs');
        $constants = config('l5-swagger.constants') ?: [];
        $excludeDirs = config('l5-swagger.paths.excludes');
        $docsJson = config('l5-swagger.paths.docs_json', 'api-docs.json');
        $securityConfig = config('l5-swagger.security', []);
        $basePath = config('l5-swagger.paths.base');
        if ($group) {
            $appDir = config('l5-swagger.doc_groups.' . $group . '.paths.annotations', $appDir);
            $docDir = config('l5-swagger.doc_groups.' . $group . '.paths.docs', $docDir);
            $constants = config('l5-swagger.doc_groups.' . $group . '.constants', $constants);
            $excludeDirs = config('l5-swagger.doc_groups.' . $group . '.paths.excludes', $excludeDirs);
            $docsJson = config('l5-swagger.doc_groups.' . $group . '.paths.docs_json', $group . '-' . $docsJson);
            $securityConfig = config('l5-swagger.doc_groups.' . $group . '.security', $securityConfig);
            $basePath = config('l5-swagger.doc_groups.' . $group . '.paths.base', $basePath);
        }
        if (!File::exists($docDir) || is_writable($docDir)) {
// delete all existing documentation


            $filename = $docDir . '/' . $docsJson;
            if (!File::exists($docDir)) {
                File::makeDirectory($docDir);
            }

// delete existing documentation
            if (File::exists($filename)) {
                File::deleteDirectory($filename);
            }
            
            self::defineConstants($constants);
            $swagger = \Swagger\scan($appDir, ['exclude' => $excludeDirs]);
            if (config('l5-swagger.paths.base') !== null) {

                $swagger->basePath = $basePath;
            }

            $swagger->saveAs($filename);

            if (is_array($securityConfig) && !empty($securityConfig)) {
                $documentation = collect(
                        json_decode(file_get_contents($filename))
                );
                $securityDefinitions = $documentation->has('securityDefinitions') ? collect($documentation->get('securityDefinitions')) : collect();
                foreach ($securityConfig as $key => $cfg) {
                    $securityDefinitions->offsetSet($key, self::arrayToObject($cfg));
                }
                $documentation->offsetSet('securityDefinitions', $securityDefinitions);
                file_put_contents($filename, $documentation->toJson());
            }
        }
    }

    protected static function defineConstants(array $constants)
    {
        if (! empty($constants)) {
            foreach ($constants as $key => $value) {
                defined($key) || define($key, $value);
            }
        }
    }
    
     public static function arrayToObject($array)
    {
        return json_decode(json_encode($array));
    }

}
