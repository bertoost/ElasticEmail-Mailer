<?php

namespace bertoost\Mailer\ElasticEmail\Transport;

use ElasticEmail\Api\EmailsApi;
use ElasticEmail\Configuration;
use ElasticEmail\Model\BodyContentType;
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
        ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null
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
        $url = sprintf('https://%s%s', $this->getEndpoint(), $request->getRequestTarget());

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
            foreach ($addresses as $address) {
                $replyTo[] = $this->formatAddress($address);
            }
        }

        $content = (new EmailContent())
            ->setSubject($email->getSubject())
            ->setBody($this->buildBody($email))
            ->setFrom(implode(', ', $from));

        if (null !== ($attachments = $this->buildAttachments($email))) {
            $content->setAttachments($attachments);
        }

        if (null !== ($headers = $this->buildHeaders($email))) {
            $content->setHeaders($headers);
        }

        if (!empty($replyTo)) {
            $content->setReplyTo(implode(', ', $replyTo));
        } else {
            $content->setReplyTo(implode(', ', $from));
        }

        return (new EmailTransactionalMessageData())
            ->setRecipients($this->buildRecipients($email))
            ->setContent($content);
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

        return (new TransactionalRecipient())
            ->setTo($tos)
            ->setCc($ccs)
            ->setBcc($bccs);
    }

    /**
     * @return BodyPart[]
     */
    private function buildBody(Email $email): array
    {
        $body = [];

        if (!empty($email->getTextBody())) {
            $body[] = (new BodyPart())
                ->setContent($email->getTextBody())
                ->setContentType(BodyContentType::PLAIN_TEXT);
        }

        if (!empty($email->getHtmlBody())) {
            $body[] = (new BodyPart())
                ->setContent($email->getHtmlBody())
                ->setContentType(BodyContentType::HTML);
        }

        return $body;
    }

    /**
     * @return MessageAttachment[]|null
     */
    private function buildAttachments(Email $email): ?array
    {
        $attachments = $email->getAttachments();
        if (!empty($attachments)) {
            $list = [];

            foreach ($email->getAttachments() as $attachment) {
                $headers = $attachment->getPreparedHeaders();

                $list[] = (new MessageAttachment())
                    ->setName($headers->getHeaderParameter('Content-Disposition', 'name'))
                    ->setContentType($headers->get('Content-Type')->getBody())
                    ->setBinaryContent($attachment->bodyToString());
            }

            return $list;
        }

        return null;
    }

    private function buildHeaders(Email $email): ?array
    {
        $list = [];

        $headersToBypass = ['from', 'to', 'cc', 'bcc', 'subject', 'content-type'];
        foreach ($email->getHeaders()->all() as $name => $header) {
            if (\in_array($name, $headersToBypass, true)) {
                continue;
            }

            $list[$header->getName()] = $header->getBodyAsString();
        }

        return !empty($list) ? $list : null;
    }

    private function formatAddress(Address $address): string
    {
        if (!empty($address->getName())) {
            return sprintf('%s <%s>', $address->getName(), $address->getAddress());
        }

        return $address->getAddress();
    }
}