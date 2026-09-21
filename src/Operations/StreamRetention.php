<?php

namespace SocketBridge\Operations;

use SocketBridge\Transport\RedisStreams;

final class StreamRetention
{
    public function __construct(private readonly RedisStreams $redis) {}

    /** One bounded atomic batch; every consumer group's unread and pending floor is protected. */
    public function prune(string $stream, int $cutoffMilliseconds, bool $dryRun = false, int $limit = 100, bool $allowUngrouped = false, ?string $after = null): array
    {
        $script = <<<'LUA'
local key = KEYS[1]
if redis.call('EXISTS', key) == 0 then return {0, '0-0', 0, 0, '', 0, 0, 0} end
local function less(a, b)
  local am, as = string.match(a, '^(%d+)%-(%d+)$')
  local bm, bs = string.match(b, '^(%d+)%-(%d+)$')
  if #am ~= #bm then return #am < #bm end
  if am ~= bm then return am < bm end
  if #as ~= #bs then return #as < #bs end
  return as < bs
end
local groups = redis.call('XINFO', 'GROUPS', key)
local safe = ARGV[1]
if #groups == 0 and ARGV[4] ~= '1' then safe = '0-0' end
for _, fields in ipairs(groups) do
  local group = {}
  for i=1,#fields,2 do group[fields[i]]=fields[i+1] end
  if less(group['last-delivered-id'], safe) then safe=group['last-delivered-id'] end
  local pending=redis.call('XPENDING',key,group['name'])
  if pending[1] > 0 and less(pending[2],safe) then safe=pending[2] end
end
local limit=tonumber(ARGV[3])
local start=ARGV[5] ~= '' and '('..ARGV[5] or '-'
local eligible={}
if safe ~= '0-0' then eligible=redis.call('XRANGE',key,start,'('..safe,'COUNT',limit+1) end
local count=math.min(#eligible,limit)
local floor=safe
if #eligible > limit then floor=eligible[limit+1][1] end
local cursor=count > 0 and eligible[count][1] or ARGV[5]
local deleted=0
if ARGV[2] ~= '1' and count > 0 then deleted=redis.call('XTRIM',key,'MINID','=',floor) end
local remainingStart=ARGV[2] == '1' and cursor ~= '' and '('..cursor or '-'
local remaining={}
if safe ~= '0-0' then remaining=redis.call('XRANGE',key,remainingStart,'('..safe,'COUNT',1) end
local expired={}
if ARGV[1] ~= '0-0' then expired=redis.call('XRANGE',key,'-','('..ARGV[1],'COUNT',1) end
local lag=0
local protected=0
if #expired > 0 then
  lag=math.max(0,math.floor((tonumber(string.match(ARGV[1],'^(%d+)'))-tonumber(string.match(expired[1][1],'^(%d+)')))/1000))
  if not less(expired[1][1],safe) then protected=1 end
end
return {deleted,safe,#groups,count,cursor,#remaining,lag,protected}
LUA;
        // A cursor is useful for dry-run pagination only; deletion always starts at the stream head.
        $reply = $this->redis->raw('EVAL', $script, 1, $this->redis->key($stream), max(0, $cutoffMilliseconds).'-0', $dryRun ? 1 : 0, max(1, min($limit, 1000)), $allowUngrouped ? 1 : 0, $dryRun ? ($after ?? '') : '');

        return ['deleted' => (int) $reply[0], 'protected_from' => $reply[1], 'groups' => (int) $reply[2], 'eligible' => (int) $reply[3], 'cursor' => $reply[4], 'has_more' => (bool) $reply[5], 'retention_lag_seconds' => (int) $reply[6], 'protected' => (bool) $reply[7]];
    }
}
