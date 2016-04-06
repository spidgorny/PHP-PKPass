<?php
/**
 * PKPass - Creates iOS 6 passes
 *
 * Author: Tom Schoffelen
 * Revision: Tom Janssen
 *
 * www.tomttb.com
 */

namespace PKPass;

use ZipArchive as ZipArchive;

/**
 * Class PKPass
 * @package PKPass
 */
class PKPass
{
    /**
     * Holds the path to the certificate
     * Variable: string
     */
    protected $certPath;

    /**
     * Name of the downloaded file.
     */
    protected $name;

    /**
     * Holds the files to include in the .pkpass
     * Variable: array
     */
    protected $files = [];

    /**
     * Holds the json
     * Variable: class
     */
    protected $JSON;

    /**
     * Holds the SHAs of the $files array
     * Variable: array
     */
    protected $SHAs;

    /**
     * Holds the password to the certificate
     * Variable: string
     */
    protected $certPass = '';

    /**
     * Holds the path to the WWDR Intermediate certificate
     * Variable: string
     */
    protected $WWDRcertPath = '';

    /**
     * Holds the path to a temporary folder
     */
    protected $tempPath = '/tmp/'; // Must end with slash!

    /**
     * Holds error info if an error occured
     */
    private $sError = '';

    /**
     * Holds a autogenerated uniqid to prevent overwriting other processes pass files
     */
    private $uniqid = null;

    /**
     * PKPass constructor.
     *
     * @param bool $certPath
     * @param bool $certPass
     * @param bool $JSON
     */
    public function __construct($certPath = false, $certPass = false, $JSON = false)
    {
        if ($certPath != false) {
            $this->setCertificate($certPath);
        }
        if ($certPass != false) {
            $this->setCertificatePassword($certPass);
        }
        if ($JSON != false) {
            $this->setJSON($JSON);
        }
    }

    /**
     * Sets the path to a certificate
     * Parameter: string, path to certificate
     * Return: boolean, true on succes, false if file doesn't exist
     *
     * @param $path
     *
     * @return bool
     */
    public function setCertificate($path)
    {
        if (file_exists($path)) {
            $this->certPath = $path;

            return true;
        }

        $this->sError = 'Certificate file does not exist.';

        return false;
    }

    /**
     * Sets the certificate's password
     * Parameter: string, password to the certificate
     * Return: boolean, always true
     *
     * @param $p
     *
     * @return bool
     */
    public function setCertificatePassword($p)
    {
        $this->certPass = $p;

        return true;
    }

    /**
     * Sets the path to the WWDR Intermediate certificate
     * Parameter: string, path to certificate
     * Return: boolean, always true
     *
     * @param $path
     *
     * @return bool
     */
    public function setWWDRcertPath($path)
    {
        $this->WWDRcertPath = $path;

        return true;
    }

    /**
     * Sets the path to the temporary directory (must end with a slash)
     * Parameter: string, path to temporary directory
     * Return: boolean, true on success, false if directory doesn't exist
     *
     * @param $path
     *
     * @return bool
     */
    public function setTempPath($path)
    {
        if (is_dir($path)) {
            $this->tempPath = $path;

            return true;
        } else {
            return false;
        }
    }

    /**
     * Decodes JSON and saves it to a variable
     * Parameter: json-string
     * Return: boolean, true on succes, false if json wasn't decodable
     *
     * @param $JSON
     *
     * @return bool
     */
    public function setJSON($JSON)
    {
        if (json_decode($JSON) !== false) {
            $this->JSON = $JSON;

            return true;
        }
        $this->sError = 'This is not a JSON string.';

        return false;
    }

    /**
     * Adds file to the file array
     * Parameter: string, path to file
     * Parameter: string, optional, name to create file as
     * Return: boolean, true on succes, false if file doesn't exist
     *
     * @param      $path
     * @param null $name
     *
     * @return bool
     */
    public function addFile($path, $name = null)
    {
        if (file_exists($path)) {
            $name               = ($name === null) ? basename($path) : $name;
            $this->files[$name] = $path;

            return true;
        }
        $this->sError = 'File does not exist.';

        return false;
    }

    /**
     * Creates the actual .pkpass file
     * Parameter: boolean, if output is true, send the zip file to the browser.
     * Return: zipped .pkpass file on succes, false on failure
     *
     * @param bool $output
     *
     * @return bool|string
     */
    public function create($output = false)
    {
        $paths = $this->paths();

        //Creates and saves the json manifest
        if ( !($manifest = $this->createManifest())) {
            $this->clean();

            return false;
        }

        //Create signature
        if ($this->createSignature($manifest) == false) {
            $this->clean();

            return false;
        }

        if ($this->createZip($manifest) == false) {
            $this->clean();

            return false;
        }

        // Check if pass is created and valid
        if ( !file_exists($paths['pkpass']) || filesize($paths['pkpass']) < 1) {
            $this->sError = 'Error while creating pass.pkpass. Check your Zip extension.';
            $this->clean();

            return false;
        }

        // Output pass
        if ($output != true) {

            $file = file_get_contents($paths['pkpass']);

            $this->clean();

            return $file;
        }

        $fileName = ($this->getName()) ? $this->getName() : basename($paths['pkpass']);
        header('Pragma: no-cache');
        header('Content-type: application/vnd.apple.pkpass');
        header('Content-length: ' . filesize($paths['pkpass']));
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        $this->clean();

        return file_get_contents($paths['pkpass']);
    }

    /**
     * @return mixed
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param $name
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * @param $error
     *
     * @return bool
     */
    public function checkError(&$error)
    {
        if (trim($this->sError) == '') {
            return false;
        }

        $error = $this->sError;

        return true;
    }

    /**
     * @return string
     */
    public function getError()
    {
        return $this->sError;
    }



    #################################
    #######PROTECTED FUNCTIONS#######
    #################################

    /**
     * Subfunction of create()
     * This function creates the hashes for the files and adds them into a json string.
     */
    protected function createManifest()
    {
        // Creates SHA hashes for all files in package
        $this->SHAs['pass.json'] = sha1($this->JSON);
        $hasicon                 = false;
        foreach ($this->files as $name => $path) {
            if (strtolower($name) == 'icon.png') {
                $hasicon = true;
            }
            $this->SHAs[$name] = sha1(file_get_contents($path));
        }

        if ( !$hasicon) {
            $this->sError = 'Missing required icon.png file.';
            $this->clean();

            return false;
        }

        $manifest = json_encode((object)$this->SHAs);

        return $manifest;
    }

    /**
     * Converts PKCS7 PEM to PKCS7 DER
     * Parameter: string, holding PKCS7 PEM, binary, detached
     * Return: string, PKCS7 DER
     *
     * @param $signature
     *
     * @return string
     */
    protected function convertPEMtoDER($signature)
    {
        $begin     = 'filename="smime.p7s"';
        $end       = '------';
        $signature = substr($signature, strpos($signature, $begin) + strlen($begin));

        $signature = substr($signature, 0, strpos($signature, $end));
        $signature = trim($signature);
        $signature = base64_decode($signature);

        return $signature;
    }

    /**
     * Creates a signature and saves it
     * Parameter: json-string, manifest file
     * Return: boolean, true on succes, failse on failure
     *
     * @param $manifest
     *
     * @return bool
     */
    protected function createSignature($manifest)
    {
        $paths = $this->paths();

        file_put_contents($paths['manifest'], $manifest);

        $pkcs12 = file_get_contents($this->certPath);
        $certs  = [];
        if (openssl_pkcs12_read($pkcs12, $certs, $this->certPass) == true) {
            $certdata = openssl_x509_read($certs['cert']);
            $privkey  = openssl_pkey_get_private($certs['pkey'], $this->certPass);

            if ( !empty($this->WWDRcertPath)) {

                if ( !file_exists($this->WWDRcertPath)) {
                    $this->sError = 'WWDR Intermediate Certificate does not exist';

                    return false;
                }

                openssl_pkcs7_sign(
                    $paths['manifest'],
                    $paths['signature'],
                    $certdata,
                    $privkey,
                    [],
                    PKCS7_BINARY | PKCS7_DETACHED,
                    $this->WWDRcertPath
                );
            } else {
                openssl_pkcs7_sign($paths['manifest'], $paths['signature'], $certdata, $privkey, [], PKCS7_BINARY | PKCS7_DETACHED);
            }

            $signature = file_get_contents($paths['signature']);
            $signature = $this->convertPEMtoDER($signature);
            file_put_contents($paths['signature'], $signature);

            return true;
        } else {
            $this->sError = 'Could not read the certificate';

            return false;
        }
    }

    /**
     * Creates .pkpass (zip archive)
     * Parameter: json-string, manifest file
     * Return: boolean, true on succes, false on failure
     *
     * @param $manifest
     *
     * @return bool
     */
    protected function createZip($manifest)
    {
        $paths = $this->paths();

        // Package file in Zip (as .pkpass)
        $zip = new ZipArchive();
        if ( !$zip->open($paths['pkpass'], ZipArchive::CREATE)) {
            $this->sError = 'Could not open ' . basename($paths['pkpass']) . ' with ZipArchive extension.';

            return false;
        }

        $zip->addFile($paths['signature'], 'signature');
        $zip->addFromString('manifest.json', $manifest);
        $zip->addFromString('pass.json', $this->JSON);
        foreach ($this->files as $name => $path) {
            $zip->addFile($path, $name);
        }
        $zip->close();

        return true;
    }

    /**
     * Declares all paths used for temporary files.
     */
    protected function paths()
    {
        //Declare base paths
        $paths = [
            'pkpass'    => 'pass.pkpass',
            'signature' => 'signature',
            'manifest'  => 'manifest.json',
        ];

        //If trailing slash is missing, add it
        if (substr($this->tempPath, -1) != '/') {
            $this->tempPath = $this->tempPath . '/';
        }

        //Generate a unique subfolder in the tempPath to support generating more passes at the same time without erasing/overwriting each others files
        if (empty($this->uniqid)) {
            $this->uniqid = uniqid('PKPass', true);

            if ( !is_dir($this->tempPath . $this->uniqid)) {
                mkdir($this->tempPath . $this->uniqid);
            }
        }

        //Add temp folder path
        foreach ($paths AS $pathName => $path) {
            $paths[$pathName] = $this->tempPath . $this->uniqid . '/' . $path;
        }

        return $paths;
    }

    /**
     * Removes all temporary files
     */
    protected function clean()
    {
        $paths = $this->paths();

        foreach ($paths AS $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        //Remove our unique temporary folder
        if (is_dir($this->tempPath . $this->uniqid)) {
            rmdir($this->tempPath . $this->uniqid);
        }

        return true;
    }
}
