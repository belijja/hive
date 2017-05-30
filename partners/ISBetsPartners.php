<?php
/**
 * Created by PhpStorm.
 * User: Branislav Malidzan
 * Date: 24.04.2017
 * Time: 16:28
 */
declare(strict_types = 1);

namespace Partners;

use Helpers\ConfigHelpers\ConfigManager;
use Helpers\ServerHelpers\ServerManager;
use Configs\ISBetsCodes;
use Configs\CurrencyCodes;
use Helpers\SoapHelpers\ISBetsSoapClient;

/**
 * Class ISBetsPartners
 * @package Partners
 */
class ISBetsPartners extends AbstractPartners
{
    public function __construct()
    {
        parent::__construct(new ServerManager(), new ISBetsSoapClient());
    }

    /**
     * @param array $arrayOfParams
     * @return array
     * @throws \SoapFault
     */
    public function checkAndRegisterUser(array $arrayOfParams): array
    {
        @list($userId, $skinId, $soapClient) = $arrayOfParams;
        $returnData = [];//initializing return array variable
        $userDetails = null;//initializing variable for fetching user from db
        $query = $this->db->prepare("SELECT c.extern_username, 
                                             datediff(now(), ud.updatetime) AS updatediff, 
                                             (ud.logintime <= ud.acttime) AS isFirst, u.* 
                                             FROM users u 
                                             JOIN casino_ids c ON u.userid = c.user_id 
                                             JOIN udata ud ON u.userid = ud.uid 
                                             WHERE c.provider_id = :ISBetsProviderId 
                                             AND c.casino_id = :userId 
                                             AND c.skin_id = :skinId");//fetching user details
        if (!$query->execute([
            ':ISBetsProviderId' => ConfigManager::getISBets('localProviderId'),
            ':userId'           => $userId,
            ':skinId'           => $skinId
        ])
        ) {//if query fail
            throw new \SoapFault('DB_ERROR', 'Query failed.', '');
        }
        if ($query->rowCount() > 0) {
            $userDetails = $query->fetch(\PDO::FETCH_OBJ);
            $returnData = [
                'status'  => 1,
                'pokerId' => $userDetails->userid
            ];
        }
        if ($query->rowCount() == 0 || $userDetails->updatediff > 14 || $userDetails->isFirst) {
            $ISBetsUserInfo = $this->soapClient->getUserInfo($userId, $skinId, $soapClient);
            /*if (is_soap_fault($ISBetsUserInfo) || $ISBetsUserInfo->GetUserInfoResult->_UserID != $userId) {
                throw new \SoapFault('CONNECTION_ERROR', 'Error connecting to SKS endpoint.');
            }*///delete comments on prod
            $user = $ISBetsUserInfo->GetUserInfoResult->_UserInfo;//making variable name shorter
            $params = [];
            if ($ISBetsUserInfo->GetUserInfoResult->_FatherID > 2) {
                $this->checkAndAddAffiliate($skinId, $ISBetsUserInfo->GetUserInfoResult->_FatherID, $soapClient);
                $params['affiliateId'] = $ISBetsUserInfo->GetUserInfoResult->_FatherID;
            }
            $params['providerId'] = ConfigManager::getISBets('localProviderId');
            $params['userId'] = $userId;
            $params['skinId'] = $skinId;
            $params['password'] = 'invalid password';
            $params['email'] = $user->Email;
            $params['firstName'] = $user->Firstname;
            $params['lastName'] = $user->Lastname;
            if (isset($user->RegionResidenceCode) && ISBetsCodes::getPGDARegionCodes($user->RegionResidenceCode) != null) {
                $params['state'] = ISBetsCodes::getPGDARegionCodes($user->RegionResidenceCode);
            } else if (isset($user->ProvinceResidenceCode) && ISBetsCodes::getPGDAProvinceCodes($user->ProvinceResidenceCode) != null) {
                $params['state'] = ISBetsCodes::getPGDAProvinceCodes($user->ProvinceResidenceCode);
            } else {
                $params['state'] = 99;
            }
            $params['city'] = $user->City;
            $params['street'] = $user->Address;
            $params['country'] = ISBetsCodes::getCountryCodes($user->Country)['code'];
            $params['zip'] = $user->Zip;
            $params['dateOfBirth'] = substr($user->Birthdate, 0, 10);
            $params['phone'] = $user->Phone;
            $params['currencyCode'] = ISBetsCodes::getCurrencyCodes((string)$user->Currency)['code'];
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
                $currencyId = CurrencyCodes::getCurrencyIds($params['currencyCode']);//making variable shorter for using in error_log function
                if (CurrencyCodes::getCurrencyIds($params['currencyCode']) != $userDetails->curid) {
                    error_log('PATH: ' . __FILE__ . ' LINE: ' . __LINE__ . ' METHOD: ' . __METHOD__ . 'Currency code has changed from ' . $userDetails->curid . ' to ' . $currencyId);
                    throw new \SoapFault('INVALID_ARG', 'Currency code has changed.');
                }
                if ($params['username'] == $userDetails->username && $params['email'] == $userDetails->email && $params['firstName'] == $userDetails->firstname && $params['lastName'] == $userDetails->lastname && $params['city'] == $userDetails->city && $params['street'] == $userDetails->street && $params['state'] == $userDetails->state && $params['zip'] == $userDetails->zip && $params['dateOfBirth'] == $userDetails->dob && $params['phone'] == $userDetails->phone && $params['country'] == $userDetails->country && $params['externalUsername'] == $userDetails->extern_username && !$userDetails['isFirst']) {
                    return $returnData;
                }
                $functionName = 'UpdatePokerPlayer';
            }
            return $this->serverManager->callExternalMethod($functionName, $params);
        }
        return $returnData;
    }

    /**
     * @param int $skinId
     * @param int $fatherId
     * @param \SoapClient|null $soapClient
     * @return void
     */
    private function checkAndAddAffiliate(int $skinId, int $fatherId, \SoapClient $soapClient = null): void
    {
        $query = $this->db->prepare("SELECT poker_affilid 
                                             FROM provider_affil_mapping 
                                             WHERE provider_affilid = :fatherId 
                                             AND provider_id = :ISBetsProviderId");
        if (!$query->execute([
                ':fatherId'         => $fatherId,
                ':ISBetsProviderId' => ConfigManager::getISBets('localProviderId')
            ]) || $query->rowCount() == 0
        ) {
            $ISBetsUserInfo = $this->soapClient->getUserInfo($fatherId, $skinId, $soapClient);
            if (is_soap_fault($ISBetsUserInfo) || $ISBetsUserInfo->GetUserInfoResult->_UserID != $fatherId || $ISBetsUserInfo->GetUserInfoResult->_UserInfo->UserType != 20) {
                return;
                //throw new \SoapFault('CONNECTION_ERROR', 'Error connecting to SKS endpoint.');
            }
            $commit = false;
            $this->db->beginTransaction();
            $query = $this->db->prepare("INSERT INTO affiliates (name, email, phone, city, street, country, zip, state) 
                                                 VALUES (:username, :email, :phone, :city, :address, :country, :zip, 1)");
            $user = $ISBetsUserInfo->GetUserInfoResult->_UserInfo;//making variable name shorter
            if ($query->execute([
                    ':username' => $user->Username,
                    ':email'    => $user->Email,
                    ':phone'    => $user->Phone,
                    ':city'     => $user->City,
                    ':address'  => $user->Address,
                    ':country'  => ISBetsCodes::getCountryCodes($user->Country)['code'],
                    ':zip'      => $user->Zip
                ]) && $query->rowCount() > 0
            ) {
                $pokerAffiliateId = $this->db->lastInsertId();
                $query = $this->db->prepare("INSERT INTO provider_affil_mapping (provider_id, provider_affilid, poker_affilid) 
                                                     VALUES (:providerId, :providerAffiliateId, :pokerAffiliateId)");
                if ($query->execute([
                    ':providerId'          => ConfigManager::getISBets('localProviderId'),
                    ':providerAffiliateId' => $fatherId,
                    ':pokerAffiliateId'    => $pokerAffiliateId
                ])
                ) {
                    $commit = true;
                }
            }
            if ($commit) {
                $this->db->commit();
            } else {
                $this->db->rollBack();
            }
        }
    }
}