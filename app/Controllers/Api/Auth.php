<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\UserModel;
use CodeIgniter\API\ResponseTrait;

class Auth extends BaseController
{
    use ResponseTrait;

    private string $apiUrl = 'https://lakminiint.com/ideamart/bytehub/ReCon/middleWare/requestManager.php';
    private string $appId  = '23';

    public function requestOtp()
    {
        $payload = $this->request->getJSON(true) ?? $this->request->getPost();
        $mobile  = trim((string) ($payload['mobile'] ?? ''));

        if ($mobile === '') {
            return $this->failValidationErrors([
                'mobile' => 'Mobile number is required.',
            ]);
        }

        $response = $this->callApi([
            'funName' => 'requestOTP',
            'app_id'  => $this->appId,
            'mobile'  => $mobile,
        ]);

        if (! isset($response->response)) {
            return $this->fail('Unable to reach OTP server.');
        }

        $userModel    = model(UserModel::class);
        $user         = $userModel->where('mobile', $mobile)->first();
        $remoteStatus = $response->response;

        if ($remoteStatus === 'Exist') {
            // Upstream service confirms the mobile is already active; skip OTP and log in.
            if (! $user) {
                $userId = bin2hex(random_bytes(16));
                $userModel->insert([
                    'id'           => $userId,
                    'mobile'       => $mobile,
                    'reg_datetime' => date('Y-m-d H:i:s'),
                    'status'       => 'active',
                ]);
                $user = $userModel->find($userId);
            } elseif (($user['status'] ?? 'active') !== 'active') {
                $userModel->update($user['id'], [
                    'status' => 'active',
                ]);
                $user = $userModel->find($user['id']);
            }

            session()->set([
                'isLoggedIn' => true,
                'user_id'    => $user['id'],
                'mobile'     => $user['mobile'],
                'status'     => $user['status'] ?? 'active',
                'provider'   => 'mobile',
            ]);
            session()->regenerate(true);

            return $this->respond([
                'status'   => 'success',
                'mode'     => 'login',
                'response' => 'Exist',
                'message'  => 'Welcome back! You are now signed in.',
                'user'     => [
                    'id'       => $user['id'],
                    'mobile'   => $user['mobile'],
                    'status'   => $user['status'] ?? 'active',
                    'provider' => 'mobile',
                ],
            ]);
        }

        if ($remoteStatus === 'Success') {
            $reference = $response->data->referenceNo
                ?? $response->referenceNo
                ?? $response->reference
                ?? null;

            $remainingAttempts = $response->data->remainingAttempts
                ?? $response->data->remainingAttempt
                ?? $response->data->remaining
                ?? null;

            return $this->respond([
                'status'              => 'success',
                'mode'                => 'otp',
                'response'            => 'Success',
                'reference_no'        => $reference,
                'mobile'              => $mobile,
                'message'             => 'Verification code sent successfully.',
                'remaining_attempts'  => $remainingAttempts,
            ]);
        }

        if (in_array($remoteStatus, ['OTPLimit', 'OTP_LIMIT_EXCEEDED', 'LimitExceeded', 'OtpLimitExceeded'], true)) {
            $message = $response->message ?? 'You have reached the OTP request limit. Please try again later.';
            return $this->fail($message, 429);
        }

        $message = $response->message ?? 'Unable to request OTP. Please try again.';
        return $this->fail($message);
    }

    public function verifyOtp()
    {
        $payload     = $this->request->getJSON(true) ?? $this->request->getPost();
        $mobile      = trim((string) ($payload['mobile'] ?? ''));
        $referenceNo = trim((string) ($payload['reference_no'] ?? ''));
        $otp         = trim((string) ($payload['otp'] ?? ''));

        if ($mobile === '' || $referenceNo === '' || $otp === '') {
            return $this->failValidationErrors([
                'mobile'       => 'Mobile is required.',
                'reference_no' => 'Reference number is required.',
                'otp'          => 'OTP code is required.',
            ]);
        }

        $response = $this->callApi([
            'funName'     => 'verifyOtp',
            'app_id'      => $this->appId,
            'referenceNo' => $referenceNo,
            'otp_code'    => $otp,
            'mobile'      => $mobile,
        ]);

        if (($response->response ?? null) !== 'Success') {
            return $this->fail('OTP verification failed.');
        }

        $userModel = model(UserModel::class);
        $user      = $userModel->where('mobile', $mobile)->first();

        if (! $user) {
            $userId = bin2hex(random_bytes(16));
            // Mobile-only flow creates the record on demand so we can remember returning users.
            $userModel->insert([
                'id'           => $userId,
                'mobile'       => $mobile,
                'reg_datetime' => date('Y-m-d H:i:s'),
                'status'       => 'active',
            ]);
            $user = $userModel->find($userId);
        } elseif (($user['status'] ?? 'active') !== 'active') {
            $userModel->update($user['id'], [
                'status' => 'active',
            ]);
            $user = $userModel->find($user['id']);
        }

        session()->set([
            'isLoggedIn' => true,
            'user_id'    => $user['id'],
            'mobile'     => $user['mobile'],
            'status'     => $user['status'] ?? 'active',
            'provider'   => 'mobile',
        ]);

        session()->regenerate();

        return $this->respond([
            'status'  => 'success',
            'message' => 'OTP verified successfully!',
            'user'    => [
                'id'       => $user['id'],
                'mobile'   => $user['mobile'],
                'status'   => $user['status'] ?? 'active',
                'provider' => 'mobile',
            ],
        ]);
    }

    public function unregister()
    {
        $payload = $this->request->getJSON(true) ?? $this->request->getPost();
        $mobile  = trim((string) ($payload['mobile'] ?? ''));

        $session = session();
        $sessionMobile = (string) ($session->get('mobile') ?? '');

        if ($sessionMobile !== '') {
            $mobile = $sessionMobile;
        }

        if ($mobile === '') {
            return $this->failValidationErrors([
                'mobile' => 'Mobile number is required.',
            ]);
        }

        $response = $this->callApi([
            'funName' => 'unRegistration',
            'app_id'  => $this->appId,
            'mobile'  => $mobile,
        ]);

        if (! isset($response->response)) {
            return $this->fail('Unable to reach unsubscribe service.');
        }

        $remoteStatus = (string) ($response->response ?? '');

        if (! in_array($remoteStatus, ['Success', 'SUCCESS', 'Unsubscribed', 'UNSUBSCRIBED'], true)) {
            $message = $response->message ?? 'Unable to unsubscribe at this time.';
            return $this->fail($message);
        }

        $userModel = model(UserModel::class);
        $user      = $userModel->where('mobile', $mobile)->first();

        if ($user) {
            $userModel->update($user['id'], [
                'status' => 'inactive',
            ]);
        }

        if ($session->get('isLoggedIn')) {
            $session->destroy();
        }

        return $this->respond([
            'status'  => 'success',
            'message' => 'You have been unsubscribed successfully.',
        ]);
    }

    public function me()
    {
        $session = session();
        if (! $session->get('isLoggedIn')) {
            return $this->respond([
                'status' => 'guest',
                'user'   => null,
            ]);
        }

        $userId = $session->get('user_id');
        if (! $userId) {
            $session->destroy();
            return $this->respond([
                'status' => 'guest',
                'user'   => null,
            ]);
        }

        $userModel = model(UserModel::class);
        $user      = $userModel->find($userId);

        if (! $user) {
            $session->destroy();
            return $this->respond([
                'status' => 'guest',
                'user'   => null,
            ]);
        }

        return $this->respond([
            'status' => 'success',
            'user'   => [
                'id'       => $user['id'],
                'mobile'   => $user['mobile'],
                'status'   => $user['status'] ?? 'active',
                'provider' => 'mobile',
            ],
        ]);
    }

    public function logout()
    {
        $session = session();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $session->start();
        }

        $session->destroy();

        return $this->respond([
            'status'  => 'success',
            'message' => 'Logged out successfully.',
            'user'    => null,
        ]);
    }

    private function callApi(array $payload): object
    {
        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            curl_close($ch);
            return (object) ['response' => null];
        }
        curl_close($ch);

        return json_decode($body) ?? (object) [];
    }
}
