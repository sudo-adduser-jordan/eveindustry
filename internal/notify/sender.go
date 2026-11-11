package notify

import (
	"context"
	"encoding/json"

	webpush "github.com/SherClockHolmes/webpush-go"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type WebPushSender struct {
	Subject    string
	PublicKey  string
	PrivateKey string
	TTL        int
}

func (s *WebPushSender) Configured() bool {
	return s != nil && s.PublicKey != "" && s.PrivateKey != ""
}

func (s *WebPushSender) Send(ctx context.Context, sub store.PushSubscription, payload map[string]any) (sent, gone bool) {
	if !s.Configured() {
		return false, false
	}
	wpSub := &webpush.Subscription{Endpoint: sub.Endpoint, Keys: webpush.Keys{P256dh: sub.PublicKey, Auth: sub.AuthToken}}
	raw, err := json.Marshal(payload)
	if err != nil {
		return false, false
	}
	resp, err := webpush.SendNotificationWithContext(ctx, raw, wpSub, &webpush.Options{
		Subscriber:      s.Subject,
		VAPIDPublicKey:  s.PublicKey,
		VAPIDPrivateKey: s.PrivateKey,
		TTL:             s.TTL,
	})
	if err != nil {
		return false, false
	}
	defer resp.Body.Close()
	switch {
	case resp.StatusCode >= 200 && resp.StatusCode < 300:
		return true, false
	case resp.StatusCode == 404 || resp.StatusCode == 410:
		return false, true
	default:
		return false, false
	}
}
