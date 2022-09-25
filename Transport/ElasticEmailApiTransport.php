<?php

namespace bertoost\Mailer\ElasticEmail\Transport;

use ElasticEmail\Api\EmailsApi;
use ElasticEmail\Configuration;
use ElasticEmail\Model\BodyPart;
use ElasticEmail\Model\EmailContent;
use ElasticEmail\Model\EmailTransactionalMessageData;
use ElasticEmail\Model\MessageAttachment;
use ElasticEmail\Model\TransactionalRecipient;
use GuzzleHttp\Client;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ElasticEmailApiTransport extends AbstractApiTransport
{
    private const HOST = 'api.elasticemail.com';

    private EmailsApi $api;

    public function __construct(
        string $apiKey,
        HttpClientInterface $client = null,
        EventDispatcherInterface $dispatcher = null,
        LoggerInterface $logger = null
    ) {
        $this->api = new EmailsApi(
            new Client(),
            Configuration::getDefaultConfiguration()
                ->setApiKey('X-ElasticEmail-ApiKey', $apiKey)
        );

        parent::__construct($client, $dispatcher, $logger);
    }

    public function __toString(): string
    {
        return sprintf('elasticemail+api://%s', $this->getEndpoint());
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $request = $this->api->emailsTransactionalPostRequest($this->getPayload($email));
        $url = sprintf('%s%s', $this->getEndpoint(), $request->getRequestTarget());

        $response = $this->client->request($request->getMethod(), $url, [
            'headers' => $request->getHeaders(),
            'body' => (string)$request->getBody(),
        ]);

        try {
            $statusCode = $response->getStatusCode();
            $result = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            throw new HttpTransportException('Unable to send an email: '.$response->getContent(false).sprintf(' (code %d).', $statusCode), $response);
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach the remote Elastic Email server.', $response, 0, $e);
        }

        if (200 !== $statusCode) {
            throw new HttpTransportException('Unable to send an email '.sprintf('(code %d).', $statusCode), $response);
        }

        // The response needs to contain a 'MessageID' and 'TransactionID' keys
        if (!\array_key_exists('MessageID', $result) || !\array_key_exists('TransactionID', $result)) {
            throw new HttpTransportException(sprintf('Unable to send an email: "%s" malformed api response.', $response->getContent(false)), $response);
        }

        $sentMessage->setMessageId($result['MessageID']);

        return $response;
    }

    private function getEndpoint(): ?string
    {
        return ($this->host ?: self::HOST).($this->port ? ':'.$this->port : '');
    }

    private function getPayload(Email $email): EmailTransactionalMessageData
    {
        $from = [];
        foreach ($email->getFrom() as $address) {
            $from[] = $this->formatAddress($address);
        }

        $replyTo = [];
        if (null !== ($addresses = $email->getReplyTo())) {
            foreach ($addresses as $replyEmail => $replyName) {
                $replyTo[] = $this->formatAddress($replyEmail, $replyName);
            }
        }

        return new EmailTransactionalMessageData([
            'recipients' => $this->buildRecipients($email),
            'content' => new EmailContent([
                'body' => $this->buildBody($email),
                'from' => implode(', ', $from),
                'subject' => $email->getSubject(),
                'attachments' => $this->buildAttachments($email),
                'headers' => $this->buildHeaders($email),
                'reply_to' => !empty($replyTo) ? implode(', ', $replyTo) : null,
            ]),
        ]);
    }

    private function buildRecipients(Email $email): TransactionalRecipient
    {
        $tos = [];
        foreach ($email->getTo() as $address) {
            $tos[] = $this->formatAddress($address);
        }

        $ccs = [];
        foreach ($email->getCc() as $address) {
            $ccs[] = $this->formatAddress($address);
        }

        $bccs = [];
        foreach ($email->getBcc() as $address) {
            $bccs[] = $this->formatAddress($address);
        }

        return new TransactionalRecipient([
            'to' => !empty($tos) ? $tos : null,
            'cc' => !empty($ccs) ? $ccs : null,
            'bcc' => !empty($bccs) ? $bccs : null,
        ]);
    }

    /**
     * @return BodyPart[]
     */
    private function buildBody(Email $email): array
    {
        $body = [];

        if (!empty($email->getTextBody())) {
            $body[] = new BodyPart([
                'content_type' => 'PlainText',
                'content' => $email->getTextBody(),
            ]);
        }

        if (!empty($email->getHtmlBody())) {
            $body[] = new BodyPart([
                'content_type' => 'HTML',
                'content' => $email->getHtmlBody(),
            ]);
        }

        return $body;
    }

    /**
     * @return MessageAttachment[]
     */
    private function buildAttachments(Email $email): array
    {
        $list = [];

        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();

            $list[] = new MessageAttachment([
                'name' => $headers->getHeaderParameter('Content-Disposition', 'name'),
                'content_type' => $headers->get('Content-Type')->getBody(),
                'binary_content' => $attachment->bodyToString(),
            ]);
        }

        return $list;
    }

    private function buildHeaders(Email $email): array
    {
        $list = [];

        $headersToBypass = ['from', 'to', 'cc', 'bcc', 'subject', 'content-type'];
        foreach ($email->getHeaders()->all() as $name => $header) {
            if (\in_array($name, $headersToBypass, true)) {
                continue;
            }

            $list[$header->getName()] = $header->getBodyAsString();
        }

        return $list;
    }

    private function formatAddress(Address $address): string
    {
        if (!empty($address->getName())) {
            return sprintf('%s <%s>', $address->getName(), $address->getAddress());
        }

        return $address->getAddress();
    }
}