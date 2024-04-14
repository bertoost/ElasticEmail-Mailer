<?php

namespace bertoost\Mailer\ElasticEmail\Tests\Transport;

use bertoost\Mailer\ElasticEmail\Transport\ElasticEmailApiTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ElasticEmailApiTransportTest extends TestCase
{
    /**
     * @dataProvider getTransportData
     */
    public function testToString(ElasticEmailApiTransport $transport, string $expected)
    {
        $this->assertSame($expected, (string) $transport);
    }

    public function getTransportData(): array
    {
        return [
            [
                new ElasticEmailApiTransport('KEY'),
                'elasticemail+api://api.elasticemail.com',
            ],
            [
                (new ElasticEmailApiTransport('KEY'))->setHost('example.com'),
                'elasticemail+api://example.com',
            ],
            [
                (new ElasticEmailApiTransport('KEY'))->setHost('example.com')->setPort(99),
                'elasticemail+api://example.com:99',
            ],
        ];
    }

    public function testSend()
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->assertSame('POST', $method);
            $this->assertSame('https://api.elasticemail.com/api.elasticemail.com/v4/emails/transactional', $url);
            $this->assertContains('X-ElasticEmail-ApiKey: KEY', $options['headers']);

            $body = json_decode($options['body'], true);

            $this->assertSame('Test Suite <no-reply@bertoost.com>', $body['Content']['From']);
            $this->assertSame('Bert Oost <hello@bertoost.com>', $body['Recipients']['To'][0]);
            $this->assertSame('Hello!', $body['Content']['Subject']);
            $this->assertSame('PlainText', $body['Content']['Body'][0]['ContentType']);
            $this->assertSame('Hello There!', $body['Content']['Body'][0]['Content']);

            return new MockResponse(json_encode(['MessageID' => 'foobar', 'TransactionID' => 'foobar']), [
                'http_code' => 200,
            ]);
        }, 'https://api.elasticemail.com/');

        $transport = new ElasticEmailApiTransport('KEY', $client);

        $mail = new Email();
        $mail->subject('Hello!')
            ->to(new Address('hello@bertoost.com', 'Bert Oost'))
            ->from(new Address('no-reply@bertoost.com', 'Test Suite'))
            ->text('Hello There!');

        $message = $transport->send($mail);

        $this->assertSame('foobar', $message->getMessageId());
    }
}