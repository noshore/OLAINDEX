<?php
/**
 * This file is part of the wangningkai/OLAINDEX.
 * (c) wangningkai <i@ningkai.wang>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
if (!function_exists('is_json')) {
    /**
     * 判断字符串是否是json
     *
     * @param $json
     * @return bool
     */
    function is_json($json)
    {
        json_decode($json, true);
        return (json_last_error() === JSON_ERROR_NONE);
    }
}
if (!function_exists('url_encode')) {
    /**
     * 解析路径
     *
     * @param $path
     *
     * @return string
     */
    function url_encode($path): string
    {
        $url = [];
        foreach (explode('/', $path) as $key => $value) {
            if (empty(!$value)) {
                $url[] = rawurlencode($value);
            }
        }
        return @implode('/', $url);
    }
}
if (!function_exists('trans_request_path')) {
    /**
     * 处理请求路径
     *
     * @param $path
     * @param bool $query
     * @param bool $isFile
     * @return string
     */
    function trans_request_path($path, $query = true, $isFile = false): string
    {
        $originPath = trans_absolute_path($path);
        $queryPath = trim($originPath, '/');
        $queryPath = url_encode(rawurldecode($queryPath));
        if (!$query) {
            return $queryPath;
        }
        $requestPath = empty($queryPath) ? '/' : ":/{$queryPath}:/";
        if ($isFile) {
            return rtrim($requestPath, ':/');
        }
        return $requestPath;
    }
}
if (!function_exists('trans_absolute_path')) {
    /**
     * 获取绝对路径
     *
     * @param $path
     *
     * @return mixed
     */
    function trans_absolute_path($path)
    {
        $path = str_replace(['/', '\\', '//'], '/', $path);
        $parts = array_filter(explode('/', $path), 'strlen');
        $absolutes = [];
        foreach ($parts as $part) {
            if ('.' === $part) {
                continue;
            }
            if ('..' === $part) {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }
        return str_replace('//', '/', '/' . implode('/', $absolutes) . '/');
    }
}
if (!function_exists('flash_message')) {
    /**
     * 操作成功或者失败的提示
     *
     * @param string $message
     * @param bool $success
     */
    function flash_message($message = '成功', $success = true): void
    {
        $alertType = $success ? 'success' : 'danger';
        \Session::put('alertMessage', $message);
        \Session::put('alertType', $alertType);
    }
}
if (!function_exists('setting')) {
    /**
     * 获取设置
     * @param string $key
     * @param string $default
     * @return mixed
     */
    function setting($key = '', $default = '')
    {
        $setting = \Cache::remember('settings', 60 * 60 * 2, static function () {
            try {
                $setting = \App\Models\Setting::all();
            } catch (Exception $e) {
                return [];
            }
            $data = [];
            foreach ($setting->toArray() as $detail) {
                $data[$detail['name']] = $detail['value'];
            }
            return $data;
        });
        $setting = collect($setting);
        return $key ? $setting->get($key, $default) : $setting->all();
    }
}
if (!function_exists('setting_set')) {
    /**
     * 更新设置
     * @param string $key
     * @param string $value
     * @return mixed
     */
    function setting_set($key = '', $value = '')
    {
        $value = is_array($value) ? json_encode($value) : $value;
        \App\Models\Setting::query()->updateOrCreate(['name' => $key], ['value' => $value]);
        return refresh_setting();
    }
}
if (!function_exists('refresh_setting')) {
    /**
     * 刷新设置缓存
     * @return array
     */
    function refresh_setting()
    {
        $settingData = [];
        try {
            $settingModel = \App\Models\Setting::all();
        } catch (Exception $e) {
            $settingModel = [];
        }
        foreach ($settingModel->toArray() as $detail) {
            $settingData[$detail['name']] = $detail['value'];
        }

        \Cache::forever('settings', $settingData);

        return collect($settingData)->toArray();
    }
}
if (!function_exists('install_path')) {
    /**
     * 安装路径
     * @param string $path
     * @return string
     */
    function install_path($path = '')
    {
        return storage_path('install' . ($path ? DIRECTORY_SEPARATOR . $path : $path));
    }
}
if (!function_exists('refresh_token')) {
    /**
     * 刷新账户token
     * @param \App\Models\Account $account
     * @param bool $force
     * @return bool
     * @throws \ErrorException
     */
    function refresh_token($account, $force = false)
    {
        if (!$account) {
            return false;
        }
        $existingRefreshToken = $account->refresh_token;
        if (!$force) {
            $expires = strtotime($account->access_token_expires);
            $hasExpired = $expires - time() <= 30 * 10; // 半小时刷新token
            if (!$hasExpired) {
                return false;
            }
        }
        $token = \App\Service\AuthorizeService::init()->bind($account->toArray())->refreshAccessToken($existingRefreshToken);
        $token = $token->toArray();
        $access_token = array_get($token, 'access_token');
        $refresh_token = array_get($token, 'refresh_token');
        $expires = array_has($token, 'expires_in') ? time() + array_get($token, 'expires_in') : 0;
        $account->access_token = $access_token;
        $account->refresh_token = $refresh_token;
        $account->access_token_expires = date('Y-m-d H:i:s', $expires);
        $saved = $account->save();
        if (!$saved) {
            return false;
        }
        return true;
    }
}
if (!function_exists('refresh_account')) {
    /**
     * 刷新账户信息
     * @param \App\Models\Account $account
     * @return bool
     * @throws ErrorException
     */
    function refresh_account($account)
    {
        if (!$account) {
            return false;
        }
        refresh_token($account);
        $response = \App\Service\OneDrive::init()->bind($account->toArray())->getDriveInfo();
        if ($response['errno'] === 0) {
            $extend = array_get($response, 'data');
            $account->account_email = array_get($extend, 'owner.user.email', '');
            $account->extend = $extend;
            $account->status = \App\Models\Account::STATUS_ON;
            $account->save();
        } else {
            $response = \App\Service\OneDrive::init()->bind($account->toArray())->getAccountInfo();
            $extend = array_get($response, 'data');
            $account->account_email = $response['errno'] === 0 ? array_get($extend, 'userPrincipalName') : '';
            $account->extend = $extend;
            $account->status = \App\Models\Account::STATUS_OFF;
            $account->save();
        }
        return true;
    }
}