<?php
namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * HTTP handler that uses PHP's HTTP stream wrapper.
 */
class StreamHandler
{
    private $lastHeaders = [];

    /**
     * Sends an HTTP request.
     *
     * @param RequestInterface $request Request to send.
     * @param array $options Request transfer options.
     *
     * @return PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options)
    {
        // Sleep if there is a delay specified.
        if (isset($options['delay'])) {
            usleep($options['delay'] * 1000);
        }

        try {
            // Does not support the expect header.
            $request = $request->withoutHeader('Expect');
            $stream = $this->createStream($request, $options);

            return $this->createResponse($request, $options, $stream);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Exception $e) {
            // Determine if the error was a networking error.
            $message = $e->getMessage();
            // This list can probably get more comprehensive.
            if (strpos($message, 'getaddrinfo') // DNS lookup failed
                || strpos($message, 'Connection refused')
                || strpos($message, "couldn't connect to host") // error on HHVM
            ) {
                $e = new ConnectException($e->getMessage(), $request, $e);
            }

            return new RejectedPromise(
                RequestException::wrapException($request, $e)
            );
        }
    }

    private function createResponse(
        RequestInterface $request,
        array $options,
        $stream
    ) {
        $hdrs = $this->lastHeaders;
        $this->lastHeaders = [];
        $parts = explode(' ', array_shift($hdrs), 3);
        $ver = explode('/', $parts[0])[1];
        $status = $parts[1];
        $reason = isset($parts[2]) ? $parts[2] : null;
        $headers = \GuzzleHttp\headers_from_lines($hdrs);
        list ($stream, $headers) = $this->checkDecode($options, $headers, $stream);
        $stream = Psr7\stream_for($stream);
        $sink = $this->createSink($stream, $options);
        $response = new Psr7\Response($status, $headers, $sink, $ver, $reason);

        if (isset($options['on_headers'])) {
            try {
                $options['on_headers']($response);
            } catch (\Exception $e) {
                $msg = 'An error was encountered during the on_headers event';
                $ex = new RequestException($msg, $request, $response, $e);

                return new RejectedPromise($ex);
            }
        }

        if ($sink !== $stream) {
            $this->drain($stream, $sink);
        }

        return new FulfilledPromise($response);
    }

    private function createSink(StreamInterface $stream, array $options)
    {
        if (!empty($options['stream'])) {
            return $stream;
        }

        $sink = isset($options['sink'])
            ? $options['sink']
            : fopen('php://temp', 'r+');

        return is_string($sink)
            ? new Psr7\Stream(Psr7\try_fopen($sink, 'r+'))
            : Psr7\stream_for($sink);
    }

    private function checkDecode(array $options, array $headers, $stream)
    {
        // Automatically decode responses when instructed.
        if (!empty($options['decode_content'])) {
            $normalizedKeys = \GuzzleHttp\normalize_header_keys($headers);
            if (isset($normalizedKeys['content-encoding'])) {
                $encoding = $headers[$normalizedKeys['content-encoding']];
                if ($encoding[0] == 'gzip' || $encoding[0] == 'deflate') {
                    $stream = new Psr7\InflateStream(
                        Psr7\stream_for($stream)
                    );
                    // Remove content-encoding header
                    unset($headers[$normalizedKeys['content-encoding']]);
                    // Fix content-length header
                    if (isset($normalizedKeys['content-length'])) {
                        $length = (int)$stream->getSize();
                        if ($length == 0) {
                            unset($headers[$normalizedKeys['content-length']]);
                        } else {
                            $headers[$normalizedKeys['content-length']] = [$length];
                        }
                    }
                }
            }
        }

        return [$stream, $headers];
    }

    /**
     * Drains the source stream into the "sink" client option.
     *
     * @param StreamInterface $source
     * @param StreamInterface $sink
     *
     * @return StreamInterface
     * @throws \RuntimeException when the sink option is invalid.
     */
    private function drain(StreamInterface $source, StreamInterface $sink)
    {
        Psr7\copy_to_stream($source, $sink);
        $sink->seek(0);
        $source->close();

        return $sink;
    }

    /**
     * Create a resource and check to ensure it was created successfully
     *
     * @param callable $callback Callable that returns stream resource
     *
     * @return resource
     * @throws \RuntimeException on error
     */
    private function createResource(callable $callback)
    {
        $errors = null;
        set_error_handler(
            function ($_, $msg, $file, $line) use (&$errors) {
                $errors[] = [
                    'message' => $msg,
                    'file' => $file,
                    'line' => $line,
                ];

                return true;
            }
        );

        $resource = $callback();
        restore_error_handler();

        if (!$resource) {
            $message = 'Error creating resource: ';
            foreach ($errors as $err) {
                foreach ($err as $key => $value) {
                    $message .= "[$key] $value".PHP_EOL;
                }
            }
            throw new \RuntimeException(trim($message));
        }

        return $resource;
    }

    private function createStream(RequestInterface $request, array $options)
    {
        static $methods;
        if (!$methods) {
            $methods = array_flip(get_class_methods(__CLASS__));
        }

        // HTTP/1.1 streams using the PHP stream wrapper require a
        // Connection: close header
        if ($request->getProtocolVersion() == '1.1'
            && !$request->hasHeader('Connection')
        ) {
            $request = $request->withHeader('Connection', 'close');
        }

        // Ensure SSL is verified by default
        if (!isset($options['verify'])) {
            $options['verify'] = true;
        }

        $params = [];
        $context = $this->getDefaultContext($request, $options);

        if (isset($options['on_headers']) && !is_callable($options['on_headers'])) {
            throw new \InvalidArgumentException('on_headers must be callable');
        }

        if (!empty($options)) {
            foreach ($options as $key => $value) {
                $method = "add_{$key}";
                if (isset($methods[$method])) {
                    $this->{$method}($request, $context, $value, $params);
                }
            }
        }

        if (isset($options['stream_context'])) {
            if (!is_array($options['stream_context'])) {
                throw new \InvalidArgumentException('stream_context must be an array');
            }
            $context = array_replace_recursive(
                $context,
                $options['stream_context']
            );
        }

        $context = $this->createResource(
            function () use ($context, $params) {
                return stream_context_create($context, $params);
            }
        );

        return $this->createResource(
            function () use ($request, &$http_response_header, $context) {
                $resource = fopen($request->getUri(), 'r', null, $context);
                $this->lastHeaders = $http_response_header;

                return $resource;
            }
        );
    }

    private function getDefaultContext(RequestInterface $request)
    {
        $headers = '';
        foreach ($request->getHeaders() as $name => $value) {
            foreach ($value as $val) {
                $headers .= "$name: $val\r\n";
            }
        }

        $context = [
            'http' => [
                'method' => $request->getMethod(),
                'header' => $headers,
                'protocol_version' => $request->getProtocolVersion(),
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ];

        $body = (string)$request->getBody();

        if (!empty($body)) {
            $context['http']['content'] = $body;
            // Prevent the HTTP handler from adding a Content-Type header.
            if (!$request->hasHeader('Content-Type')) {
                $context['http']['header'] .= "Content-Type:\r\n";
            }
        }

        $context['http']['header'] = rtrim($context['http']['header']);

        return $context;
    }

    private function add_proxy(RequestInterface $request, &$options, $value, &$params)
    {
        if (!is_array($value)) {
            $options['http']['proxy'] = $value;
        } else {
            $scheme = $request->getUri()->getScheme();
            if (isset($value[$scheme])) {
                $options['http']['proxy'] = $value[$scheme];
            }
        }
    }

    private function add_timeout(RequestInterface $request, &$options, $value, &$params)
    {
        $options['http']['timeout'] = $value;
    }

    private function add_verify(RequestInterface $request, &$options, $value, &$params)
    {
        if ($value === true) {
            // PHP 5.6 or greater will find the system cert by default. When
            // < 5.6, use the Guzzle bundled cacert.
            if (PHP_VERSION_ID < 50600) {
                $options['ssl']['cafile'] = \GuzzleHttp\default_ca_bundle();
            }
        } elseif (is_string($value)) {
            $options['ssl']['cafile'] = $value;
            if (!file_exists($value)) {
                throw new \RuntimeException("SSL CA bundle not found: $value");
            }
        } elseif ($value === false) {
            $options['ssl']['verify_peer'] = false;

            return;
        } else {
            throw new \InvalidArgumentException('Invalid verify request option');
        }

        $options['ssl']['verify_peer'] = true;
        $options['ssl']['allow_self_signed'] = false;
    }

    private function add_cert(RequestInterface $request, &$options, $value, &$params)
    {
        if (is_array($value)) {
            $options['ssl']['passphrase'] = $value[1];
            $value = $value[0];
        }

        if (!file_exists($value)) {
            throw new \RuntimeException("SSL certificate not found: {$value}");
        }

        $options['ssl']['local_cert'] = $value;
    }

    private function add_progress(RequestInterface $request, &$options, $value, &$params)
    {
        $this->addNotification(
            $params,
            function ($code, $a, $b, $c, $transferred, $total) use ($value) {
                if ($code == STREAM_NOTIFY_PROGRESS) {
                    $value($total, $transferred, null, null);
                }
            }
        );
    }

    private function add_debug(RequestInterface $request, &$options, $value, &$params)
    {
        if ($value === false) {
            return;
        }

        static $map = [
            STREAM_NOTIFY_CONNECT => 'CONNECT',
            STREAM_NOTIFY_AUTH_REQUIRED => 'AUTH_REQUIRED',
            STREAM_NOTIFY_AUTH_RESULT => 'AUTH_RESULT',
            STREAM_NOTIFY_MIME_TYPE_IS => 'MIME_TYPE_IS',
            STREAM_NOTIFY_FILE_SIZE_IS => 'FILE_SIZE_IS',
            STREAM_NOTIFY_REDIRECTED => 'REDIRECTED',
            STREAM_NOTIFY_PROGRESS => 'PROGRESS',
            STREAM_NOTIFY_FAILURE => 'FAILURE',
            STREAM_NOTIFY_COMPLETED => 'COMPLETED',
            STREAM_NOTIFY_RESOLVE => 'RESOLVE',
        ];
        static $args = [
            'severity',
            'message',
            'message_code',
            'bytes_transferred',
            'bytes_max',
        ];

        $value = \GuzzleHttp\debug_resource($value);
        $ident = $request->getMethod().' '.$request->getUri();
        $this->addNotification(
            $params,
            function () use ($ident, $value, $map, $args) {
                $passed = func_get_args();
                $code = array_shift($passed);
                fprintf($value, '<%s> [%s] ', $ident, $map[$code]);
                foreach (array_filter($passed) as $i => $v) {
                    fwrite($value, $args[$i].': "'.$v.'" ');
                }
                fwrite($value, "\n");
            }
        );
    }

    private function addNotification(array &$params, callable $notify)
    {
        // Wrap the existing function if needed.
        if (!isset($params['notification'])) {
            $params['notification'] = $notify;
        } else {
            $params['notification'] = $this->callArray(
                [
                    $params['notification'],
                    $notify,
                ]
            );
        }
    }

    private function callArray(array $functions)
    {
        return function () use ($functions) {
            $args = func_get_args();
            foreach ($functions as $fn) {
                call_user_func_array($fn, $args);
            }
        };
    }
}
