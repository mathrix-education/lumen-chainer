<?php

namespace Mathrix\Chainer;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mathrix\Lumen\Exceptions\Http\Http400BadRequestException;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Class Chainer.
 *
 * @author Mathieu Bour <mathieu@mathrix.fr>
 * @copyright Mathrix Education SA.
 * @since 1.0.0
 */
class Chainer
{
    private $request;
    private $subRequests;
    private $responseData = [];
    private $bearerToken;


    /**
     * Chainer constructor.
     *
     * @param Request $request
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->bearerToken = $request->header("Authorization");
    }


    /**
     * @return JsonResponse
     * @throws Http400BadRequestException
     */
    public function process()
    {
        $this->subRequests = $this->request->input("requests", []);
        foreach ($this->subRequests as $subRequest) {
            $this->processIndividualRequest($subRequest);
        }
        return new JsonResponse($this->responseData);
    }


    /**
     * @param array $subRequest
     *
     * @throws Http400BadRequestException
     */
    private function processIndividualRequest(array $subRequest)
    {
        $key = $subRequest["name"];
        $request = Request::create(
            $this->resolve($subRequest["url"]),
            $subRequest["method"],
            $subRequest["data"] ?? []
        );
        $request->setJson(new ParameterBag($subRequest["data"] ?? []));
        $request->headers->set("Authorization", $this->bearerToken);
        app()->offsetSet("request", $request);
        $response = app()->handle($request);
        $this->responseData[$key] = [
            "method" => $subRequest["method"],
            "url" => $this->resolve($subRequest["url"]),
            "body" => json_decode($response->getContent(), true)
        ];
        app()->offsetSet("request", $this->request);
    }


    /**
     * @param string $toParse
     *
     * @return mixed|string
     * @throws Http400BadRequestException
     */
    private function resolve(string $toParse)
    {
        $matches = [];
        $result = preg_match_all("/\{(\w+\.[\.\w+]+\w+)\}/", $toParse, $matches);
        if ($result === 0) {
            return $toParse;
        } else {
            $keys = $matches[1];
            foreach ($keys as $key) {
                $toParse = str_replace("{" . $key . "}", $this->resolveParam($key), $toParse);
            }
            return $toParse;
        }
    }


    /**
     * @param string $key
     *
     * @return array
     * @throws Http400BadRequestException
     */
    private function resolveParam(string $key)
    {
        $keys = explode(".", $key);
        $array = $this->responseData;
        $subKey = array_shift($keys);
        $array = $array[$subKey]["body"];
        foreach ($keys as $subKey) {
            if (!empty($array[$subKey])) {
                $array = $array[$subKey];
            } else {
                throw new Http400BadRequestException($this->responseData, "Unable to find key `$key`.");
            }
        }
        return $array;
    }
}
