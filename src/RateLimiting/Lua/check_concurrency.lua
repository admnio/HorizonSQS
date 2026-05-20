-- KEYS[1] = sunset:rl:c:<limitName>:<bucketKey>  -- the concurrency set
-- KEYS[2] = sunset:rl:slot:<slotId>              -- the slot key (for TTL)
-- ARGV[1] = max
-- ARGV[2] = slotTtlSeconds
-- ARGV[3] = slotId

local setKey = KEYS[1]
local slotKey = KEYS[2]
local max = tonumber(ARGV[1])
local ttl = tonumber(ARGV[2])
local slot = ARGV[3]

local count = tonumber(redis.call('SCARD', setKey))
if count >= max then
  -- Approximate retry-after as the most-recent slot's remaining TTL.
  local sampled = redis.call('SRANDMEMBER', setKey)
  local retry = ttl
  if sampled then
    local sampledTtl = redis.call('PTTL', 'sunset:rl:slot:' .. sampled)
    if sampledTtl and sampledTtl > 0 then
      retry = math.max(1, math.ceil(sampledTtl / 1000))
    end
  end
  return { 0, retry }
end

redis.call('SADD', setKey, slot)
redis.call('SET', slotKey, '1', 'EX', ttl)
redis.call('EXPIRE', setKey, ttl + 5)
return { 1, slot }
