<?php

namespace Dlw\Mail;

use Illuminate\Mail\Transport\Transport;
use Swift_MimePart;
use Swift_Attachment;
use Swift_Mime_SimpleMessage;
use SparkPost\SparkPostResponse;
use SparkPost\SparkPost;

/**
 * Class SparkPostTransport
 * @package App\Services\SparkPost
 */
class SparkPostTransport extends Transport
{
    protected $client;
    protected $options = [];

    /**
     * SparkPostTransport constructor.
     * @param SparkPost $client
     * @param array $options
     * @throws \Exception
     */
    public function __construct(SparkPost $client, array $options = [])
    {
        $this->client = $client;
        $this->options = $options;
    }

    /**
     * @param Swift_Mime_SimpleMessage $message
     * @param null $failedRecipients
     * @return int
     * @throws \Exception
     */
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        $this->beforeSendPerformed($message);
        $payload = $this->payload( $message );
        $response = $this->client->transmissions->post($payload)->wait();
        $message->getHeaders()->addTextHeader(
            'X-SparkPost-Transmission-ID', $this->getTransmissionId($response)
        );
        $this->sendPerformed($message);
        return $this->getTotalAcceptedRecipients($response);
    }

    /**
     * @param Swift_Mime_SimpleMessage $message
     * @return array
     */
    protected function getFrom( Swift_Mime_SimpleMessage $message ){
        $from = (array) $message->getFrom();
        if( is_array($from) && count($from) ){
            [$email] = array_keys( $from );
            [$name] = array_values( $from );
        }
        return [
            'name' => $name,
            'email' => $email,
        ];
    }

    /**
     * @param Swift_Mime_SimpleMessage $message
     * @return string
     */
    protected function getReplyTo( Swift_Mime_SimpleMessage $message ){
        $replyToString = '';
        $replyTo = (array) $message->getReplyTo();
        if( is_array($replyTo) && count($replyTo) ){
            [$email] = array_keys( $replyTo );
            [$name] = array_values( $replyTo );
            $replyToString = trim( $name ?? '' . ' <'. $email ?? '' .'>');
        }
        return $replyToString;
    }

    /**
     * @param Swift_Mime_SimpleMessage $message
     * @return array
     */
    protected function getTo( Swift_Mime_SimpleMessage $message ){
        return $this->getFormattedAddressArray( (array) $message->getTo() );
    }

    /**
     * @param Swift_Mime_SimpleMessage $message
     * @return array
     */
    protected function getCc( Swift_Mime_SimpleMessage $message ){
        return $this->getFormattedAddressArray( (array) $message->getCc() );
    }

    /**
     * @param Swift_Mime_SimpleMessage $message
     * @return array
     */
    protected function getBcc( Swift_Mime_SimpleMessage $message ){
        return $this->getFormattedAddressArray( (array) $message->getBcc() );
    }

    /**
     * @param array $addresses
     * @return array
     */
    protected function getFormattedAddressArray( array $addresses ){
        $formattedAddresses = [];
        if( count($addresses) ){
            foreach ( $addresses as $email => $name) {
                $formattedAddresses[] = ['address' => compact('name', 'email')];
            }
        }
        return $formattedAddresses;
    }

    /**
     * @param Swift_Mime_SimpleMessage $message
     * @return mixed
     */
    protected function getAttachments(Swift_Mime_SimpleMessage $message)
    {
        return collect( $message->getChildren() )
            ->filter(function ($child) {
                return $child instanceof Swift_Attachment;
            })
            ->map(function ($child) {
                return [
                    'name' => $child->getHeaders()->get('content-type')->getParameter('name'),
                    'type' => $child->getContentType(),
                    'data' => base64_encode($child->getBody()),
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * @param Swift_Mime_SimpleMessage $message
     * @return string
     */
    protected function getSubject(Swift_Mime_SimpleMessage $message)
    {
        return $message->getSubject() ?: '';
    }

    /**
     * @param Swift_Mime_SimpleMessage $message
     * @param $mimeType
     * @return mixed
     */
    protected function getMimePart(Swift_Mime_SimpleMessage $message, $mimeType)
    {
        return collect($message->getChildren())
            ->filter(function ($child) {
                return $child instanceof Swift_MimePart;
            })
            ->filter(function (Swift_MimePart $child) use ($mimeType) {
                return strpos($child->getContentType(), $mimeType) === 0;
            })
            ->first();
    }

    /**
     * @param Swift_Mime_SimpleMessage $message
     * @return string
     */
    protected function getHtmlBody(Swift_Mime_SimpleMessage $message){
        if( $message->getBodyContentType() === 'text/plain' )
            return null;
        return $message->getBody();
    }

    /**
     * @param Swift_Mime_SimpleMessage $message
     * @return string
     */
    protected function getTextBody(Swift_Mime_SimpleMessage $message){
        if( $message->getBodyContentType() === 'text/plain' )
            return $message->getBody();
        $mimePart = $this->getMimePart( $message, 'text/plain');
        if( $mimePart && $mimePart instanceof Swift_MimePart )
            return $mimePart->getBody();
    }

    /**
     * @param Swift_Mime_SimpleMessage $message
     * @return array
     */
    protected function payload(Swift_Mime_SimpleMessage $message){
        $options = $this->options['options'] ?? [];
        $metadata = $this->options['metadata'] ?? [];
        $substitutionData = $this->options['substitution_data'] ?? [];
        $headers = $this->options['headers'] ?? [];
        $to = $this->getTo( $message );
        $cc = $this->getCc( $message );
        $bcc = $this->getBcc( $message );
        return [
            'options' => count($options) ? $options : null,
            'metadata' => count($metadata) ? $metadata : null,
            'substitution_data' => count($substitutionData) ? $substitutionData : null,
            'name' => $this->options['name'] ?? '',
            'campaign_id' => $this->options['campaign_id'] ?? '',
            'description' => $this->options['description'] ?? '',
            'recipients' => count($to) ? $to : null,
            'cc' => count($cc) ? $cc : null,
            'bcc' => count($bcc) ? $bcc : null,
            'content' => [
                'from' => $this->getFrom( $message ),
                'subject' => $this->getSubject( $message ),
                'reply_to' => $this->getReplyTo( $message ),
                'attachments' => $this->getAttachments( $message ),
                'headers' => count($headers) ? $headers : null,
                'html' => $this->getHtmlBody( $message ),
                'text' => $this->getTextBody( $message ),
            ],
        ];
    }

    /**
     * @param SparkPostResponse $response
     * @return mixed
     */
    protected function getResultsFromResponse( SparkPostResponse $response ){
        [ 'results' => $results ] = (array) $response->getBody();
        return $results;
    }

    /**
     * @param SparkPostResponse $response
     * @return mixed
     */
    protected function getTransmissionId( SparkPostResponse $response ){
        [ 'id' => $id ] = $this->getResultsFromResponse( $response );
        return $id ?? 0;
    }

    /**
     * @param SparkPostResponse $response
     * @return int
     */
    protected function getTotalAcceptedRecipients( SparkPostResponse $response ){
        [ 'total_accepted_recipients' => $recipients ] = $this->getResultsFromResponse( $response );
        return $recipients ?? 0;
    }

    /**
     * @param SparkPostResponse $response
     * @return int
     */
    protected function getTotalRejectedRecipients( SparkPostResponse $response ){
        [ 'total_rejected_recipients' => $recipients ] = $this->getResultsFromResponse( $response );
        return $recipients ?? 0;
    }
}