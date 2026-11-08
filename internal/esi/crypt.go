package esi

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
)

// laravelPayload mirrors Crypt::encryptString output: base64 of JSON with
// base64 iv/value and a hex HMAC over iv+value.
type laravelPayload struct {
	IV    string `json:"iv"`
	Value string `json:"value"`
	MAC   string `json:"mac"`
}

// IsLaravelEncrypted mirrors EsiToken::encrypted(): a base64 JSON envelope
// with iv, value and mac keys.
func IsLaravelEncrypted(value string) bool {
	raw, err := base64.StdEncoding.DecodeString(value)
	if err != nil {
		return false
	}
	var p laravelPayload
	if err := json.Unmarshal(raw, &p); err != nil {
		return false
	}
	return p.IV != "" && p.Value != "" && p.MAC != ""
}

// DecryptLaravelToken mirrors Crypt::decryptString for encryptString
// payloads (raw strings, no PHP serialization): verifies the HMAC, then
// AES-256-CBC decrypts. key must be the 32-byte APP_KEY (base64: decoded by
// the caller). Fails closed on any mismatch, like the PHP cast.
func DecryptLaravelToken(value string, key []byte) (string, error) {
	raw, err := base64.StdEncoding.DecodeString(value)
	if err != nil {
		return "", errors.New("bad envelope")
	}
	var p laravelPayload
	if err := json.Unmarshal(raw, &p); err != nil || p.IV == "" || p.Value == "" || p.MAC == "" {
		return "", errors.New("bad envelope")
	}
	mac, err := hex.DecodeString(p.MAC)
	if err != nil {
		return "", errors.New("bad mac")
	}
	want := hmac.New(sha256.New, key)
	want.Write([]byte(p.IV + p.Value))
	if !hmac.Equal(mac, want.Sum(nil)) {
		return "", errors.New("mac mismatch")
	}
	iv, err := base64.StdEncoding.DecodeString(p.IV)
	if err != nil {
		return "", errors.New("bad iv")
	}
	ct, err := base64.StdEncoding.DecodeString(p.Value)
	if err != nil {
		return "", errors.New("bad ciphertext")
	}
	block, err := aes.NewCipher(key)
	if err != nil {
		return "", err
	}
	if len(ct)%aes.BlockSize != 0 || len(ct) == 0 {
		return "", errors.New("bad block length")
	}
	plain := make([]byte, len(ct))
	cipher.NewCBCDecrypter(block, iv).CryptBlocks(plain, ct)
	// PKCS#7 unpad, validated.
	pad := int(plain[len(plain)-1])
	if pad < 1 || pad > aes.BlockSize || pad > len(plain) {
		return "", errors.New("bad padding")
	}
	for _, b := range plain[len(plain)-pad:] {
		if int(b) != pad {
			return "", errors.New("bad padding")
		}
	}
	return string(plain[:len(plain)-pad]), nil
}
