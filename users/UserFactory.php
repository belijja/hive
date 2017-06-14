<?php
/**
 * Created by PhpStorm.
 * User: Branislav Malidzan
 * Date: 22.05.2017
 * Time: 14:33
 */
declare(strict_types = 1);

namespace Users;

use BackOffice\Bonus;
use Configs\GameProviderConfigs;

class UserFactory
{
    /**
     * @param array $thirdPartyServiceUser
     * @param string $gameProviderId
     * @return User
     * @throws \SoapFault
     */
    public static function getUser(array $thirdPartyServiceUser, string $gameProviderId): User
    {
        $user = null;
        foreach (GameProviderConfigs::getGameProviderConfigs() as $key => $value) {
            if ($gameProviderId == $value['providerId']) {
                if (is_null($user)) {
                    $user = $thirdPartyServiceUser['provider_id'] == 2 ? new SKSUser($thirdPartyServiceUser, $value, new Bonus()) : new TPUser($thirdPartyServiceUser, $value, new Bonus());
                }
            }
        }
        if (is_null($user)) {
            error_log('Factory user not found! ' . 'PATH: ' . __FILE__ . ' LINE: ' . __LINE__ . ' METHOD: ' . __METHOD__ . ' VARIABLE: ' . var_export($user, true));
            throw new \SoapFault('-3', 'Factory user not found');
        }
        return $user;
    }

}