<?php

namespace IMEdge\SnmpEngine\Usm;

use Amp\Socket\InternetAddress;
use FreeDSx\Asn1\Encoder\BerEncoder;
use FreeDSx\Asn1\Type\OctetStringType;
use FreeDSx\Asn1\Type\SequenceType;
use IMEdge\SnmpEngine\SnmpCredential;
use IMEdge\SnmpPacket\Error\SnmpAuthenticationException;
use IMEdge\SnmpPacket\Message\SnmpV3Message;
use IMEdge\SnmpPacket\Message\SnmpV3ScopedPdu;
use IMEdge\SnmpPacket\ParseHelper;
use IMEdge\SnmpPacket\Pdu\Pdu;
use IMEdge\SnmpPacket\Usm\AuthenticationModule;
use IMEdge\SnmpPacket\Usm\PrivacyModule;
use IMEdge\SnmpPacket\Usm\RemoteEngine;
use IMEdge\SnmpPacket\Usm\UserBasedSecurityModel;
use RuntimeException;

class ClientContext
{
    protected const MAX_LOCAL_ARBITRARY_INTEGER = 2 ** 32 - 1;
    protected static ?BerEncoder $encoder = null;

    public readonly RemoteEngine $engine;
    protected AuthenticationModule|false|null $authentication = null;
    protected ?PrivacyModule $privacy = null;
    protected int $localArbitraryInteger;
    protected ?UserBasedSecurityModel $usm = null;

    /**
     * @throws SnmpAuthenticationException
     */
    public function __construct(
        public readonly InternetAddress $address,
        public readonly SnmpCredential $credential
    ) {
        $this->engine = new RemoteEngine();
        $this->refreshAuthenticationModel();
        $this->refreshPrivacyModel();
        $this->localArbitraryInteger = random_int(0, self::MAX_LOCAL_ARBITRARY_INTEGER);
    }

    public function wantsAuthentication(): bool
    {
        return $this->credential->securityLevel?->wantsAuthentication() ?? false;
    }

    public function wantsEncryption(): bool
    {
        return $this->credential->securityLevel?->wantsEncryption() ?? false;
    }

    public function hasAuthentication(): bool
    {
        return $this->authentication !== null;
    }

    public function hasPrivacy(): bool
    {
        return $this->privacy !== null;
    }

    /**
     * @throws SnmpAuthenticationException
     */
    public function refreshFromSecurityParameters(UserBasedSecurityModel $securityParameters): void
    {
        if ($this->engine->refreshIfOutdated($securityParameters)) {
            $this->refreshAuthenticationModel();
            $this->refreshPrivacyModel();
        }
    }

    /**
     * @throws SnmpAuthenticationException
     */
    public function authenticateOutgoing(SnmpV3Message $message): SnmpV3Message
    {
        if ($this->wantsAuthentication()) {
            return $this->getAuthenticationModule()?->authenticateOutgoingMsg($message) ?? $message;
        }

        return $message;
    }

    /**
     * @throws SnmpAuthenticationException
     */
    public function getAuthenticationModule(): ?AuthenticationModule
    {
        if ($this->authentication === null) {
            $this->refreshAuthenticationModel();
        }
        if ($this->authentication === false) {
            return null;
        }

        return $this->authentication;
    }

    protected function getEncryptionSalt(): string
    {
        if ($this->privacy === null) {
            return '';
        }

        if ($this->privacy->privacyProtocol->isDES()) {
            if ($this->localArbitraryInteger === self::MAX_LOCAL_ARBITRARY_INTEGER) {
                $this->localArbitraryInteger = 0;
            } else {
                $this->localArbitraryInteger++;
            }

            return pack('NN', $this->engine->boots, $this->localArbitraryInteger);
        } else {
            // TODO: arbitrary int might suffice?
            return random_bytes(8);
        }
    }

    /**
     * @throws SnmpAuthenticationException
     */
    protected function refreshAuthenticationModel(): void
    {
        $credential = $this->credential;
        if ($credential->authProtocol === null || $credential->authKey === null) {
            $this->authentication = false;
            $this->usm = null;
        } elseif (
            $this->authentication === null || (
                $this->authentication instanceof AuthenticationModule && !$this->authentication->equals(
                    $credential->authKey,
                    $this->engine->id,
                    $credential->authProtocol
                )
            )
        ) {
            $this->authentication = new AuthenticationModule(
                $credential->authKey,
                $this->engine->id,
                $credential->authProtocol
            );
            $this->usm = null;
        }
    }

    protected function refreshPrivacyModel(): void
    {
        $credential = $this->credential;
        if (
            $this->engine->id === ''
            || $credential->privProtocol === null
            || $credential->privKey === null
            || $credential->authProtocol === null
        ) {
            $this->privacy = null;
        } else {
            $this->privacy = new PrivacyModule(
                $credential->privKey,
                $this->engine,
                $credential->authProtocol,
                $credential->privProtocol,
            );
        }
        $this->usm = null;
    }

    public function authenticate(SnmpV3Message $message): bool
    {
        if ($this->authentication) {
            return $this->authentication->authenticateIncomingMessage($message);
        }

        throw new SnmpAuthenticationException('Cannot authenticate the given message');
    }

    public function usm(): UserBasedSecurityModel
    {
        return $this->usm ??= UserBasedSecurityModel::create(
            $this->credential->securityName ?? '',
            $this->engine,
            $this->getEncryptionSalt()
        );
    }

    public function encryptPdu(Pdu $pdu, string $salt): SnmpV3ScopedPdu
    {
        self::$encoder ??= new BerEncoder();
        if ($this->privacy === null) {
            throw new RuntimeException('Cannot encrypt, have no privacyModule');
        }
        return SnmpV3ScopedPdu::encrypted($this->privacy->encrypt(
            (self::$encoder->encode(new SequenceType(
                new OctetStringType(''), // contextEngineId??
                new OctetStringType(''), // contextName??
                $pdu->toAsn1(),
            ))),
            $salt,
        ), $pdu);
    }

    public function decryptPdu(string $encryptedPdu, string $privacyParams): Pdu
    {
        self::$encoder ??= new BerEncoder();
        if ($this->privacy === null) {
            throw new RuntimeException('Cannot decrypt, have no privacyModule');
        }
        $binary = $this->privacy->decrypt($encryptedPdu, $privacyParams);
        $pdu = SnmpV3ScopedPdu::fromAsn1(
            ParseHelper::requireSequence(self::$encoder->decode($binary), 'scopedPdu')
        )->pdu;
        if ($pdu === null) {
            throw new RuntimeException('Decrypted, but still no PDU? Logical error, should not happen');
        }

        return $pdu;
    }
}
