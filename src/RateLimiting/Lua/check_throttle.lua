-- KEYS[1] = sunset:rl:t:<limitName>:<bucketKey>  -- the sorted-set of timestamped entries
-- ARGV[1] = now (unix seconds)
-- ARGV[2] = window seconds
-- ARGV[3] = max
-- ARGV[4] = entry id (caller-provided unique string; reservation handle)

local key = KEYS[1]
local now = tonumber(ARGV[1])
local window = tonumber(ARGV[2])
local max = tonumber(ARGV[3])
local entry = ARGV[4]

redis.call('ZREMRANGEBYSCORE', key, '-inf', now - window)
local count = tonumber(redis.call('ZCARD', key))

if count >= max then
  local oldest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
  local retryAfter = 1
  if oldest[2] then
    retryAfter = math.max(1, math.ceil((tonumber(oldest[2]) + window) - now))
  end
  return { 0, retryAfter }
end

redis.call('ZADD', key, now, entry)
redis.call('EXPIRE', key, window + 5)
return { 1, entry }
