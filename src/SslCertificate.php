<?php

namespace HRobertson\X509Verify;

class SslCertificate
{
    private $pem;
    private $der;
    private $derReader;

    /**
     * SslCertificate constructor.
     *
     * @param string $certificateData PEM format certificate
     */
    public function __construct($certificate)
    {
        if (!(bool)openssl_x509_read($certificate)) {
            throw new \InvalidArgumentException("Argument could not be parsed as a PEM encoded X.509 certificate.");
        }
        $this->pem = $certificate;
        $this->derReader = new DerElement($this->getAsDer());
        return $this;
    }

    public function getAsPem()
    {
        return $this->pem;
    }

    /**
     * Get certificate in DER encoding
     *
     * @return string $derEncoded on success
     * @return bool false on failure
     */
    private function getAsDer()
    {
        if (!isset($this->der)) {
            $cert_split = preg_split('/(-----((BEGIN)|(END)) CERTIFICATE-----)/', $this->pem);
            $this->der = base64_decode($cert_split[1]);
        }
        return $this->der;
    }

    /**
     * Attempt to decrypt encrypted signature using the public key from the given certificate
     *
     * @param string $encryptedSignature Signature extracted from leaf certificate
     * @param SslCertificate $issuerCertificate Suspected signing certificate
     * @return string Decrypted DER encoded signature as binary string
     * @return bool false on failure to decrypt signature
     * @throws \ErrorException
     */
    public static function decryptSignature($encryptedSignature, SslCertificate $issuerCertificate)
    {
        // Extract the public key from the issuer cert, which is what has
        // been used to encrypt the signature in the cert.
        $pubKey = openssl_pkey_get_public($issuerCertificate->getAsPem());
        if ($pubKey === false) {
            throw new \ErrorException('Failed to extract the public key from the issuer cert.');
        }
        // Attempt to decrypt the encrypted signature using the CA's public
        // key, returning the decrypted signature in $decryptedSig.  If
        // it can't be decrypted, this ca was not used to sign it for sure...
        $rc = openssl_public_decrypt($encryptedSignature, $decryptedSignature, $pubKey);

        if ($rc === false) {
            return false;
        }
        return $decryptedSignature;
    }

    /**
     * Determine if given certificate was used to sign this one
     * Note that more than one CA cert can give a positive result, some certs
     * re-issue signing certs after having only changed the expiration dates.
     *
     * @param SslCertificate $issuerCertificate - Certificate that possibly signed this one
     * @return bool true if $issuerCertificate signed this cert, false if not
     * @throws \ErrorException, \RuntimeException
     */
    public function isSignedBy(SslCertificate $issuerCertificate)
    {
        // Grab the encrypted signature from the DER encoded cert.
        $encryptedSig = $this->getSignature();

        // Attempt to decrypt the encrypted signature using the CA's public
        // key. If it can't be decrypted, the issuer cert was not used to sign it
        $decryptedSig = self::decryptSignature($encryptedSig, $issuerCertificate);
        if ($decryptedSig === false) {
            return false;
        }
        // We now have the decrypted signature, which is DER encoded
        // asn1 data containing the signature algorithm and signature hash.
        // Now we need what was originally hashed by the issuer, which is
        // the original DER encoded certificate without the issuer and
        // signature information.
        $origCert = $this->getTbsCertificate();

        // Get the oid of the signature hash algorithm, which is required
        // to generate our own hash of the original cert.  This hash is
        // what will be compared to the issuers hash.
        $signatureAlgorithm = self::getSignatureAlgorithm($decryptedSig);

        // Get the issuer generated hash from the decrypted signature.
        $decryptedHash = $this->getSignatureHash($decryptedSig);
        // Ok, hash the original unsigned cert with the same algorithm
        // and if it matches $decryptedHash we have a winner.
        $certHash = hash($signatureAlgorithm, $origCert);
        return ($decryptedHash === $certHash);
    }

    /**
     * Extract encrypted signature
     *
     * This signature is encrypted by the public key of the issuing signer.
     *
     * @return string Encrypted signature as binary string
     */
    public function getSignature()
    {
        $cert = $this->derReader->getContent();
        return $cert[2]->getContent();
    }

    /**
     * Obtain DER cert with issuer and signature sections stripped.
     *
     * @return string TBSCertificate component as binary string
     */
    public function getTbsCertificate()
    {
        $cert = $this->derReader->getContent();
        return $cert[0]->getAsBytes();
    }

    /**
     * Get signature algorithm oid from DER encoded signature data.
     * Expects decrypted signature data from a certificate in DER format.
     * This ASN1 data should contain the following structure:
     * SEQUENCE
     *    SEQUENCE
     *       OID    (signature algorithm)
     *       NULL
     *    OCTET STRING (signature hash)
     *
     * @return string oid
     * @throws \ErrorException
     */
    public static function getSignatureAlgorithmOid($derSignature)
    {
        $der = new DerElement($derSignature);
        if ($der->getTagNumber() !== 0x10) {
            throw new \ErrorException('Invalid DER passed to getSignatureAlgorithmOid()');
        }

        $sig = $der->getContent();
        if (count($sig) !== 2) {
            throw new \ErrorException('Invalid DER passed to getSignatureAlgorithmOid()');
        }
        $sigDetails = $sig[0]->getContent();
        if ($sigDetails[0]->getTagNumber() !== 0x06) {
            throw new \ErrorException('Invalid DER passed to getSignatureAlgorithmOid');
        }

        $oid_data = $sigDetails[0]->getContent();

        // Unpack the OID
        $oid = floor(ord($oid_data[0]) / 40);
        $oid .= '.' . ord($oid_data[0]) % 40;
        $value = 0;
        $i = 1;
        while ($i < strlen($oid_data)) {
            $value = $value << 7;
            $value = $value | (ord($oid_data[$i]) & 0x7f);
            if (!(ord($oid_data[$i]) & 0x80)) {
                $oid .= '.' . $value;
                $value = 0;
            }
            $i++;
        }
        return $oid;
    }

    public static function getSignatureAlgorithm($derSignature)
    {
        $oid = self::getSignatureAlgorithmOid($derSignature);
        switch ($oid) {
            case '1.2.840.113549.2.2':
                $algo = 'md2';
                break;
            case '1.2.840.113549.2.4':
                $algo = 'md4';
                break;
            case '1.2.840.113549.2.5':
                $algo = 'md5';
                break;
            case '1.3.14.3.2.18':
                $algo = 'sha';
                break;
            case '1.3.14.3.2.26':
                $algo = 'sha1';
                break;
            case '2.16.840.1.101.3.4.2.1':
                $algo = 'sha256';
                break;
            case '2.16.840.1.101.3.4.2.2':
                $algo = 'sha384';
                break;
            case '2.16.840.1.101.3.4.2.3':
                $algo = 'sha512';
                break;
            default:
                throw new \ErrorException('Unknown signature hash algorithm oid: ' . $oid);
                break;
        }
        return $algo;
    }

    /**
     * Get signature hash from DER encoded signature data.
     * Expects decrypted signature data from a certificate in DER format.
     * This ASN1 data should contain the following structure:
     * SEQUENCE
     *    SEQUENCE
     *       OID    (signature algorithm)
     *       NULL
     *    OCTET STRING (signature hash)
     *
     * @return string hash
     * @throws \ErrorException
     */
    public static function getSignatureHash($derSignature)
    {
        $der = new DerElement($derSignature);
        if ($der->getTagNumber() !== 0x10) {
            throw new \ErrorException('Invalid DER passed to getSignatureAlgorithmOid()');
        }

        $sig = $der->getContent();
        if (count($sig) !== 2) {
            throw new \ErrorException('Invalid DER passed to getSignatureAlgorithmOid()');
        }
        if ($sig[1]->getTagNumber() !== 0x04) {
            throw new \ErrorException('Invalid DER passed to getSignatureAlgorithmOid');
        }

        $hash = $sig[1]->getContent();

        return bin2hex($hash);
    }
}