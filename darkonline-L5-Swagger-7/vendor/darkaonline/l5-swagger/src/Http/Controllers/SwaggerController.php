<?php

namespace L5Swagger\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Response;
use L5Swagger\Exceptions\L5SwaggerException;
use L5Swagger\Generator;


class SwaggerController extends BaseController
{

    /**
     * @var L5Swagger\Generator
     */
    protected $generator;

    public function __construct(Generator $generator)
    {
        $this->generator = $generator;
    }

    /**
     * Dump api-docs.json content endpoint.
     *
     * @param string $jsonFile
     *
     * @return \Response
     */
    public function docs($jsonFile = null)
    {

        $extension = 'json';
        $targetFile = config('l5-swagger.paths.docs_json', 'api-docs.json');

        if (! is_null($jsonFile)) {
            $targetFile = $jsonFile;
            $extension = explode('.', $jsonFile)[1];
        }

        if (config('l5-swagger.api.separated_doc')) {
            $group = explode('.', Request::route()->getName())[1];
            $filePath = config('l5-swagger.doc_groups.'.$group.'.paths.doc', config('l5-swagger.paths.docs')) . '/' .
                (!is_null($jsonFile) ? $jsonFile : config(
                    'l5-swagger.doc_groups.'.$group.'.paths.docs_json',
                    $group.'-'.config('l5-swagger.paths.docs_json', 'api-docs.json')
                ));
        } else {
            $filePath = config('l5-swagger.paths.docs') . '/' .
                (!is_null($jsonFile) ? $jsonFile : config('l5-swagger.paths.docs_json', 'api-docs.json'));
        }

        if (config('l5-swagger.generate_always') || ! File::exists($filePath)) {
            try {
                $this->generator->generateDocs();
            } catch (\Exception $e) {
                Log::error($e);

                abort(
                    404,
                    sprintf(
                        'Unable to generate documentation file to: "%s". Please make sure directory is writable. Error: %s',
                        $filePath,
                        $e->getMessage()
                    )
                );
            }
        }

        $content = File::get($filePath);

        if ($extension === 'yaml') {
            return Response::make($content, 200, [
                'Content-Type' => 'application/yaml',
                'Content-Disposition' => 'inline',
            ]);
        }

        return Response::make($content, 200, [
            'Content-Type' => 'application/json',
        ]);


    }

    /**
     * Display Swagger API page.
     *
     * @return \Response
     */
    public function api()
    {
        if (config('l5-swagger.generate_always')) {
            Generator::generateDocs();
        }

        if ($proxy = config('l5-swagger.proxy')) {
            if (! is_array($proxy)) {
                $proxy = [$proxy];
            }
            \Illuminate\Http\Request::setTrustedProxies($proxy, \Illuminate\Http\Request::HEADER_X_FORWARDED_ALL);
        }
        $docsRoute = 'l5-swagger.docs';
        $docsJson = config('l5-swagger.paths.docs_json', 'api-docs.json');
        $sort = config('l5-swagger.operations_sort');
        $additionalConfigUrl = config('l5-swagger.additional_config_url');
        $validatorUrl = config('l5-swagger.validator_url');
        $title = config('l5-swagger.api.title');
        $oauth2CallbackRoute = 'l5-swagger.oauth2_callback';
        if (config('l5-swagger.api.separated_doc')) {
            $group = explode('.', Request::route()->getName())[1];

            $docsRoute = 'l5-swagger.'.$group.'.docs';
            $docsJson = config('l5-swagger.doc_groups.'.$group.'.paths.docs_json', $docsJson);

            $sort = config('l5-swagger.doc_groups.'.$group.'.operations_sort', $sort);
            $additionalConfigUrl = config('l5-swagger.doc_groups.'.$group.'.additional_config_url', $additionalConfigUrl);
            $validatorUrl = config('l5-swagger.doc_groups.'.$group.'.validator_url', $validatorUrl);
            $title = config('l5-swagger.doc_groups.'.$group.'.api.title', $group.' - '.$title);
//            $oauth2CallbackRoute = 'l5-swagger.'.$group.'.oauth2_callback';
        }

        // Need the / at the end to avoid CORS errors on Homestead systems.
        $response = Response::make(
            view('l5-swagger::index', [
                'secure'             => Request::secure(),
                'operationsSorter'   => config('l5-swagger.operations_sort'),
                'configUrl'          => config('l5-swagger.additional_config_url'),
                'validatorUrl'       => config('l5-swagger.validator_url'),
                'urlToDocs'          => route($docsRoute, $docsJson),
                'operationsSorter'   => $sort,
                'configUrl'          => $additionalConfigUrl,
                'validatorUrl'       => $validatorUrl,
                'title'              => $title,
//                'oauth2_callback'    => route($oauth2CallbackRoute),
            ]),
            200
        );

        return $response;
    }

    /**
     * Display Oauth2 callback pages.
     *
     * @return string
     * @throws L5SwaggerException
     */
    public function oauth2Callback()
    {
        return File::get(swagger_ui_dist_path('oauth2-redirect.html'));

    }
}
