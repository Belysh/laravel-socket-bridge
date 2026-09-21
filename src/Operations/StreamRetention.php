<?php

namespace SocketBridge\Operations;

use SocketBridge\Transport\RedisStreams;

final class StreamRetention
{
    public function __construct(private readonly RedisStreams $redis) {}

    /** Inspect all group cursors and pending floors atomically with the deletion. */
    public function prune(string $stream, int $cutoffMilliseconds, bool $dryRun = false, int $limit = 1000, bool $allowUngrouped = false): array
    {
        $script = <<<'LUA'
local key = KEYS[1]
if redis.call('EXISTS', key) == 0 then return {0, '0-0', 0, 0} end
local function less(a, b)
  local am, as = string.match(a, '^(%d+)%-(%d+)$')
  local bm, bs = string.match(b, '^(%d+)%-(%d+)$')
  if #am ~= #bm then return #am < #bm end
  if am ~= bm then return am < bm end
  if #as ~= #bs then return #as < #bs end
  return as < bs
end
local groups = redis.call('XINFO', 'GROUPS', key)
if #groups == 0 and ARGV[4] ~= '1' then return {0, '0-0', 0, 0} end
local floor = ARGV[1]
for _, fields in ipairs(groups) do
  local group = {}
  for i=1,#fields,2 do group[fields[i]]=fields[i+1] end
  if less(group['last-delivered-id'], floor) then floor=group['last-delivered-id'] end
  local pending=redis.call('XPENDING',key,group['name'])
  if pending[1] > 0 and less(pending[2],floor) then floor=pending[2] end
end
local limit=tonumber(ARGV[3])
local eligible=redis.call('XRANGE',key,'-','('..floor,'COUNT',limit+1)
local count=math.min(#eligible,limit)
if #eligible > limit then floor=eligible[limit+1][1] end
if ARGV[2] == '1' or count == 0 then return {0,floor,#groups,count} end
return {redis.call('XTRIM',key,'MINID','=',floor),floor,#groups,count}
LUA;
        $reply = $this->redis->raw('EVAL', $script, 1, $this->redis->key($stream), max(0, $cutoffMilliseconds).'-0', $dryRun ? 1 : 0, max(1, min($limit, 10000)), $allowUngrouped ? 1 : 0);

        return ['deleted' => (int) $reply[0], 'protected_from' => $reply[1], 'groups' => (int) $reply[2], 'eligible' => (int) $reply[3]];
    }
}
