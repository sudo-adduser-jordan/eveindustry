package esi

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"testing"
)

func laravelEncrypt(plain string, key []byte) string {
	iv := make([]byte, aes.BlockSize)
	_, _ = rand.Read(iv)
	padded := []byte(plain)
	pad := aes.BlockSize - len(padded)%aes.BlockSize
	for i := 0; i < pad; i++ {
		padded = append(padded, byte(pad))
	}
	block, _ := aes.NewCipher(key)
	ct := make([]byte, len(padded))
	cipher.NewCBCEncrypter(block, iv).CryptBlocks(ct, padded)
	ivs := base64.StdEncoding.EncodeToString(iv)
	vals := base64.StdEncoding.EncodeToString(ct)
	mac := hmac.New(sha256.New, key)
	mac.Write([]byte(ivs + vals))
	raw, _ := json.Marshal(laravelPayload{IV: ivs, Value: vals, MAC: hex.EncodeToString(mac.Sum(nil))})
	return base64.StdEncoding.EncodeToString(raw)
}

func TestLaravelCryptRoundTrip(t *testing.T) {
	key := make([]byte, 32)
	for i := range key {
		key[i] = byte(i)
	}
	token := "refresh-token-abc-123"
	enc := laravelEncrypt(token, key)
	if !IsLaravelEncrypted(enc) {
		t.Fatal("must detect envelope")
	}
	if IsLaravelEncrypted(token) || IsLaravelEncrypted("") || IsLaravelEncrypted("!!!") {
		t.Fatal("raw values must not detect")
	}
	got, err := DecryptLaravelToken(enc, key)
	if err != nil || got != token {
		t.Fatalf("round trip: %q %v", got, err)
	}
	wrong := make([]byte, 32)
	if _, err := DecryptLaravelToken(enc, wrong); err == nil {
		t.Fatal("wrong key must fail closed")
	}
	if _, err := DecryptLaravelToken(enc+"tampered", key); err == nil {
		t.Fatal("tampered envelope must fail")
	}
}
