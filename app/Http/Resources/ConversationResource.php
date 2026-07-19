<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewerId = $request->user()?->id;
        $otherUser = $this->user_id_1 === $viewerId ? $this->user2 : $this->user1;
        $otherProfile = $otherUser?->profile;

        $lastMessage = $this->whenLoaded('latestMessage', fn () => $this->latestMessage ? [
            'content' => $this->latestMessage->content,
            'sender_id' => $this->latestMessage->sender_id,
            'created_at' => $this->latestMessage->created_at,
        ] : null);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'other_user' => $otherProfile ? [
                'id' => $otherUser->id,
                'display_name' => $otherProfile->display_name,
                'photo' => $otherProfile->photos->first()?->url,
                'pronouns' => $otherProfile->pronouns->pluck('label'),
            ] : null,
            'last_message' => $lastMessage,
            'unread_count' => (int) ($this->unread_count ?? 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
