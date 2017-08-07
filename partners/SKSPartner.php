<?php
/**
 * Created by PhpStorm.
 * User: Branislav Malidzan
 * Date: 24.04.2017
 * Time: 16:28
 */
declare(strict_types = 1);

namespace Partners;

use Helpers\ServerHelpers\ServerManager;
use Helpers\SoapHelpers\SKSSoapClient;
use Containers\ServiceContainer;

/**
 * Class SKSPartners
 * @package Partners
 */
class SKSPartner extends ServiceContainer implements IPartner
{


    private $soapClient;
    private $serverManager;

    /**
     * SKSPartner constructor.
     * @param SKSSoapClient $soapClient
     * @param ServerManager $serverManager
     */
    public function __construct(SKSSoapClient $soapClient, ServerManager $serverManager)
    {
        parent::__construct();
        $this->soapClient = $soapClient;
        $this->serverManager = $serverManager;
    }

    /**
     * @param int $userId
     * @param int $skinId
     * @param int $partnerId
     * @param \SoapClient|null $soapClient
     * @throws \SoapFault
     * @return void
     */
    public function checkAndRegisterUser(int $userId, int $skinId, int $partnerId, \SoapClient $soapClient = null): void
    {
        $userDetails = null;//initializing variable for fetching user from db
        $query = $this->container->get('Db1')->prepare("SELECT c.extern_username, 
                                             datediff(now(), ud.updatetime) AS updatediff, 
                                             (ud.logintime <= ud.acttime) AS isFirst, u.* 
                                             FROM users u 
                                             JOIN casino_ids c ON u.userid = c.user_id 
                                             JOIN udata ud ON u.userid = ud.uid 
                                             WHERE c.provider_id = :SKSProviderId 
                                             AND c.casino_id = :userId 
                                             AND c.skin_id = :skinId");//fetching user details
        if (!$query->execute([
            ':SKSProviderId' => $partnerId,
            ':userId'        => $userId,
            ':skinId'        => $skinId
        ])) {//if query fail
            throw new \SoapFault('-3', 'Query failed.');
        }
        if ($query->rowCount() > 0) {
            $userDetails = $query->fetch(\PDO::FETCH_OBJ);
        }
        if ($query->rowCount() == 0 || $userDetails->updatediff > 14 || $userDetails->isFirst) {
            $SKSUserInfo = $this->soapClient->getUserInfo($userId, $skinId, $soapClient);
            /*if (is_soap_fault($SKSUserInfo) || $SKSUserInfo->GetUserInfoResult->_UserID != $userId) {
                throw new \SoapFault('-3', 'Error connecting to SKS endpoint.');
            }*///delete comments on prod
            $user = $SKSUserInfo->GetUserInfoResult->_UserInfo;//making variable name shorter
            $params = [];
            if ($SKSUserInfo->GetUserInfoResult->_FatherID > 2) {
                $this->checkAndAddAffiliate($skinId, $SKSUserInfo->GetUserInfoResult->_FatherID, $soapClient);
                $params['affiliateId'] = $SKSUserInfo->GetUserInfoResult->_FatherID;
            }
            $params['providerId'] = $partnerId;
            $params['userId'] = $userId;
            $params['skinId'] = $skinId;
            $params['password'] = 'invalid password';
            $params['email'] = $user->Email;
            $params['firstName'] = $user->Firstname;
            $params['lastName'] = $user->Lastname;
            if (isset($user->RegionResidenceCode) && $this->container->get('SKSConfig')->getPGDARegionCodes($user->RegionResidenceCode) != null) {
                $params['state'] = $this->container->get('SKSConfig')->getPGDARegionCodes($user->RegionResidenceCode);
            } else if (isset($user->ProvinceResidenceCode) && $this->container->get('SKSConfig')->getPGDAProvinceCodes($user->ProvinceResidenceCode) != null) {
                $params['state'] = $this->container->get('SKSConfig')->getPGDAProvinceCodes($user->ProvinceResidenceCode);
            } else {
                $params['state'] = 99;
            }
            $params['city'] = $user->City;
            $params['street'] = $user->Address;
            $params['country'] = $this->container->get('SKSConfig')->getCountryCodes($user->Country)['code'];
            $params['zip'] = $user->Zip;
            $params['dateOfBirth'] = substr($user->Birthdate, 0, 10);
            $params['phone'] = $user->Phone;
            $params['currencyCode'] = $this->container->get('SKSConfig')->getCurrencyCodes((string)$user->Currency)['code'];
            $params['externalUsername'] = $user->Username;
            if ($query->rowCount() == 0) {
                $params['active'] = 1;
                $params['temporaryNick'] = 1;
                $params['username'] = "player" . mt_rand(1000000, mt_getrandmax());
                $params['isFirstLogin'] = 1;
                $functionName = 'InsertPokerRegistration';
            } else {
                $params['username'] = $userDetails->username;
                $params['isFirstLogin'] = $userDetails->isFirst ? 1 : 0;
                $currencyId = $this->container->get('CurrencyConfig')->getCurrencyIds($params['currencyCode']);//making variable shorter for using in log method
                if ($this->container->get('CurrencyConfig')->getCurrencyIds($params['currencyCode']) != $userDetails->curid) {
                    $this->container->get('Logger')->log('error', true, 'Currency code has changed from ' . $userDetails->curid . ' to ' . $currencyId . ' PATH: ' . __FILE__ . ' LINE: ' . __LINE__ . ' METHOD: ' . __METHOD__);
                    throw new \SoapFault('-3', 'Currency code has changed.');
                }
                if ($params['username'] == $userDetails->username && $params['email'] == $userDetails->email && $params['firstName'] == $userDetails->firstname && $params['lastName'] == $userDetails->lastname && $params['city'] == $userDetails->city && $params['street'] == $userDetails->street && $params['state'] == $userDetails->state && $params['zip'] == $userDetails->zip && $params['dateOfBirth'] == $userDetails->dob && $params['phone'] == $userDetails->phone && $params['country'] == $userDetails->country && $params['externalUsername'] == $userDetails->extern_username && !$userDetails['isFirst']) {
                    return;
                }
                $functionName = 'UpdatePokerPlayer';
            }
            $this->serverManager->callExternalMethod($functionName, $params);
        }
        return;
    }

    /**
     * @param int $skinId
     * @param int $fatherId
     * @param \SoapClient|null $soapClient
     * @return void
     */
    private function checkAndAddAffiliate(int $skinId, int $fatherId, \SoapClient $soapClient = null): void
    {
        $query = $this->container->get('Db1')->prepare("SELECT poker_affilid 
                                             FROM provider_affil_mapping 
                                             WHERE provider_affilid = :fatherId 
                                             AND provider_id = :SKSProviderId");
        if (!$query->execute([
                ':fatherId'      => $fatherId,
                ':SKSProviderId' => $this->container->get('Config')->getSKS('localPartnerId')
            ]) || $query->rowCount() == 0) {
            $SKSUserInfo = $this->soapClient->getUserInfo($fatherId, $skinId, $soapClient);
            if (is_soap_fault($SKSUserInfo) || $SKSUserInfo->GetUserInfoResult->_UserID != $fatherId || $SKSUserInfo->GetUserInfoResult->_UserInfo->UserType != 20) {
                return;
            }
            $commit = false;
            $this->container->get('Db1')->beginTransaction();
            $query = $this->container->get('Db1')->prepare("INSERT INTO affiliates (name, email, phone, city, street, country, zip, state) 
                                                 VALUES (:username, :email, :phone, :city, :address, :country, :zip, 1)");
            $user = $SKSUserInfo->GetUserInfoResult->_UserInfo;//making variable name shorter
            if ($query->execute([
                    ':username' => $user->Username,
                    ':email'    => $user->Email,
                    ':phone'    => $user->Phone,
                    ':city'     => $user->City,
                    ':address'  => $user->Address,
                    ':country'  => $this->container->get('SKSConfig')->getCountryCodes($user->Country)['code'],
                    ':zip'      => $user->Zip
                ]) && $query->rowCount() > 0) {
                $pokerAffiliateId = $this->container->get('Db1')->lastInsertId();
                $query = $this->container->get('Db1')->prepare("INSERT INTO provider_affil_mapping (provider_id, provider_affilid, poker_affilid) 
                                                     VALUES (:providerId, :providerAffiliateId, :pokerAffiliateId)");
                if ($query->execute([
                    ':providerId'          => $this->container->get('Config')->getSKS('localPartnerId'),
                    ':providerAffiliateId' => $fatherId,
                    ':pokerAffiliateId'    => $pokerAffiliateId
                ])) {
                    $commit = true;
                }
            }
            if ($commit) {
                $this->container->get('Db1')->commit();
            } else {
                $this->container->get('Db1')->rollBack();
            }
        }
    }
}