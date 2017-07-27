<?php

declare(strict_types = 1);

namespace unreal4u\TelegramAPI;

use GuzzleHttp\Psr7\MultipartStream;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use unreal4u\TelegramAPI\Abstracts\TelegramMethods;
use unreal4u\TelegramAPI\Abstracts\TelegramTypes;
use unreal4u\TelegramAPI\Exceptions\ClientException as CustomClientException;
use unreal4u\TelegramAPI\InternalFunctionality\DummyLogger;
use unreal4u\TelegramAPI\InternalFunctionality\TelegramDocument;
use unreal4u\TelegramAPI\InternalFunctionality\TelegramRawData;
use unreal4u\TelegramAPI\Telegram\Types\Custom\InputFile;
use unreal4u\TelegramAPI\Telegram\Types\Custom\UnsuccessfulRequest;
use unreal4u\TelegramAPI\Telegram\Types\File;

/**
 * The main API which does it all
 */
class TgLog
{
    /**
     * @var RequestHandlerInterface
     */
    protected $requestHandler;

    /**
     * Stores the token
     * @var string
     */
    private $botToken;

    /**
     * Contains an instance to a PSR-3 compatible logger
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Stores the API URL from Telegram
     * @var string
     */
    private $apiUrl = '';

    /**
     * With this flag we'll know what type of request to send to Telegram
     *
     * 'application/x-www-form-urlencoded' is the "normal" one, which is simpler and quicker.
     * 'multipart/form-data' should be used only when you upload documents, photos, etc.
     *
     * @var string
     */
    private $formType = 'application/x-www-form-urlencoded';

    /**
     * Stores the last method name used
     * @var string
     */
    protected $methodName = '';

    /**
     * TelegramLog constructor.
     *
     * @param string $botToken
     * @param LoggerInterface $logger
     * @param RequestHandlerInterface $handler
     */
    public function __construct(string $botToken, LoggerInterface $logger = null, RequestHandlerInterface $handler = null)
    {
        $this->botToken = $botToken;

        // Initialize new dummy logger (PSR-3 compatible) if not injected
        if ($logger === null) {
            $logger = new DummyLogger();
        }
        $this->logger = $logger;

        // Initialize new Guzzle client if not injected
        if ($handler === null) {
            $handler = new GuzzleRequestHandler(null, $logger);
        }
        $this->requestHandler = $handler;

        $this->constructApiUrl();
    }

    /**
     * Prepares and sends an API request to Telegram
     *
     * @param TelegramMethods $method
     * @return TelegramTypes
     * @throws \unreal4u\TelegramAPI\Exceptions\MissingMandatoryField
     */
    public function performApiRequest(TelegramMethods $method): TelegramTypes
    {
        $this->logger->debug('Request for API call, resetting internal values', [get_class($method)]);
        $this->resetObjectValues();
        $telegramRawData = $this->sendRequestToTelegram($method, $this->constructFormData($method));
        if ($telegramRawData->isError()) {
            $this->handleOffErrorRequest($telegramRawData);
        }

        return $method::bindToObject($telegramRawData, $this->logger);
    }

    /**
     * @param TelegramMethods $method
     *
     * @return PromiseInterface
     */
    public function performAsyncApiRequest(TelegramMethods $method)
    {
        $this->logger->debug('Request for async API call, resetting internal values', [get_class($method)]);
        $this->resetObjectValues();
        return $this->sendAsyncRequestToTelegram($method, $this->constructFormData($method));
    }

    /**
     * Will download a file from the Telegram server. Before calling this function, you have to call the getFile method!
     *
     * @see \unreal4u\TelegramAPI\Telegram\Types\File
     * @see \unreal4u\TelegramAPI\Telegram\Methods\GetFile
     *
     * @param File $file
     * @return TelegramDocument
     */
    public function downloadFile(File $file): TelegramDocument
    {
        $this->logger->debug('Downloading file from Telegram, creating URL');
        $url = 'https://api.telegram.org/file/bot' . $this->botToken . '/' . $file->file_path;
        $this->logger->debug('About to perform request to begin downloading file');
        return new TelegramDocument($this->requestHandler->get($url));
    }

    /**
     * @param File $file
     *
     * @return PromiseInterface
     */
    public function downloadFileAsync(File $file): PromiseInterface
    {
        $this->logger->debug('Downloading file async from Telegram, creating URL');
        $url = 'https://api.telegram.org/file/bot' . $this->botToken . '/' . $file->file_path;
        $this->logger->debug('About to perform request to begin downloading file');

        $deferred = new Deferred();

        return $this->requestHandler->requestAsync($url)->then(function (ResponseInterface $response) use ($deferred)
        {
            $deferred->resolve(new TelegramDocument($response));
        },
            function (\Exception $exception) use ($deferred)
            {
                if (method_exists($exception, 'getResponse') && !empty($exception->getResponse()->getBody()))
                    $deferred->resolve(new TelegramDocument($exception->getResponse()));
                else
                    $deferred->reject($exception);
            });
    }

    /**
     * Builds up the Telegram API url
     * @return TgLog
     */
    final private function constructApiUrl(): TgLog
    {
        $this->apiUrl = 'https://api.telegram.org/bot' . $this->botToken . '/';
        $this->logger->debug('Built up the API URL');
        return $this;
    }

	/**
	 * This is the method that actually makes the call, which can be easily overwritten so that our unit tests can work
	 *
	 * @param TelegramMethods $method
	 * @param array $formData
	 *
	 * @return TelegramRawData
	 * @throws \Exception
	 */
    protected function sendRequestToTelegram(TelegramMethods $method, array $formData): TelegramRawData
    {
        $e = null;
        $this->logger->debug('About to perform HTTP call to Telegram\'s API');
        try {
            $response = $this->requestHandler->postAsync($this->composeApiMethodUrl($method), $formData);
            $this->logger->debug('Got response back from Telegram');
	        return $response;
        } catch (\Exception $e) {
            // It can happen that we have a network problem, in such case, we can't do nothing about it, so rethrow
            if (!method_exists($e, 'getResponse') || empty($e->getResponse())) {
                throw $e;
            }
	        return new TelegramRawData((string)$e->getResponse()->getBody(), $e);
        }
    }

    /**
     * @param TelegramMethods $method
     * @param array $formData
     *
     * @return PromiseInterface
     */
    protected function sendAsyncRequestToTelegram(TelegramMethods $method, array $formData): PromiseInterface
    {
        $this->logger->debug('About to perform async HTTP call to Telegram\'s API');
        $deferred = new Deferred();

        $promise = $this->requestHandler->postAsync($this->composeApiMethodUrl($method), $formData);
        $promise->then(function (ResponseInterface $response) use ($deferred)
        {
            $deferred->resolve(new TelegramRawData((string) $response->getBody()));
        },
            function (\Exception $exception) use ($deferred)
            {
                if (method_exists($exception, 'getResponse') && !empty($exception->getResponse()->getBody()))
                    $deferred->resolve(new TelegramRawData((string) $exception->getResponse()->getBody(), $exception));
                else
                    $deferred->reject($exception);
            });

        return $deferred->promise();
    }

    /**
     * Resets everything to the default values
     *
     * @return TgLog
     */
    private function resetObjectValues(): TgLog
    {
        $this->formType = 'application/x-www-form-urlencoded';
        $this->methodName = '';

        return $this;
    }

    /**
     * Builds up the form elements to be sent to Telegram
     *
     * @TODO Move this to apart function
     *
     * @param TelegramMethods $method
     * @return array
     * @throws \unreal4u\TelegramAPI\Exceptions\MissingMandatoryField
     */
    private function constructFormData(TelegramMethods $method): array
    {
        $result = $this->checkSpecialConditions($method);

        switch ($this->formType) {
            case 'application/x-www-form-urlencoded':
                $this->logger->debug('Creating x-www-form-urlencoded form (AKA fast request)');
                $formData = [
	                'headers' =>  [
	                	'Content-Type' => 'application/x-www-form-urlencoded',
	                ],
                    'body' => http_build_query($method->export(), '', '&'),
                ];
                break;
            case 'multipart/form-data':
                $formData = $this->buildMultipartFormData($method->export(), $result['id'], $result['stream']);
                break;
            default:
                $this->logger->critical(sprintf(
                    'Invalid form-type detected, if you incur in such a situation, this is most likely a product to '.
                    'a bug. Please copy entire line and report at %s',
                    'https://github.com/unreal4u/telegram-api/issues'
                ), [
                    $this->formType
                ]);
                $formData = [
                	'headers' => [
	                    'Content-Type' => $this->formType
                    ]
                ];
                break;
        }
        $this->logger->debug('About to send following data', $formData);

        return $formData;
    }

    /**
     * Can perform any special checks needed to be performed before sending the actual request to Telegram
     *
     * This will return an array with data that will be different in each case (for now). This can be changed in the
     * future.
     *
     * @param TelegramMethods $method
     * @return array
     */
    private function checkSpecialConditions(TelegramMethods $method): array
    {
        $this->logger->debug('Checking whether to apply special conditions to this request');
        $method->performSpecialConditions();

        $return = [false];

        foreach ($method as $key => $value) {
            if (is_object($value) && $value instanceof InputFile) {
                $this->logger->debug('About to send a file, so changing request to use multi-part instead');
                // If we are about to send a file, we must use the multipart/form-data way
                $this->formType = 'multipart/form-data';
                $return = [
                    'id' => $key,
                    'stream' => $value->getStream(),
                ];
            }
        }

        return $return;
    }

    /**
     * Builds up the URL with which we can work with
     *
     * All methods in the Bot API are case-insensitive.
     * All queries must be made using UTF-8.
     *
     * @see https://core.telegram.org/bots/api#making-requests
     *
     * @param TelegramMethods $call
     * @return string
     */
    protected function composeApiMethodUrl(TelegramMethods $call): string
    {
        $completeClassName = get_class($call);
        $this->methodName = substr($completeClassName, strrpos($completeClassName, '\\') + 1);
        $this->logger->info('About to perform API request', ['method' => $this->methodName]);

        return $this->apiUrl . $this->methodName;
    }

    /**
     * Builds up a multipart form-like array for Guzzle
     *
     * @param array $data The original object in array form
     * @param string $fileKeyName A file handler will be sent instead of a string, state here which field it is
     * @param resource $stream The actual file handler
     * @return array Returns the actual formdata to be sent
     */
    private function buildMultipartFormData(array $data, string $fileKeyName, $stream): array
    {
        $this->logger->debug('Creating multi-part form array data (complex and expensive)');
        $formData = [
        	'body' => null
        ];

        $multiPartArray = [];
        foreach ($data as $id => $value) {
            // Always send as a string unless it's a file
            $multiPart = [
                'name' => $id,
                'contents' => null,
            ];

            if ($id === $fileKeyName) {
                $multiPart['contents'] = $stream;
            } else {
                $multiPart['contents'] = (string)$value;
            }

            $multiPartArray[] = $multiPart;
        }

        $formData['body'] = new MultipartStream($multiPartArray);
        return $formData;
    }

    /**
     * @param TelegramRawData $telegramRawData
     * @return TgLog
     * @throws CustomClientException
     */
    private function handleOffErrorRequest(TelegramRawData $telegramRawData): TgLog
    {
        $errorRequest = new UnsuccessfulRequest($telegramRawData->getErrorData(), $this->logger);

        $clientException = new CustomClientException(
            $errorRequest->description,
            $errorRequest->error_code,
            $telegramRawData->getException()
        );
        $clientException->setParameters($errorRequest->parameters);
        throw $clientException;
    }
}