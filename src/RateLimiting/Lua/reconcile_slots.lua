-- KEYS[1] = sunset:rl:c:<limitName>:<bucketKey>
-- ARGV[1] = slot key prefix (e.g. 'sunset:rl:slot:')

local setKey = KEYS[1]
local prefix = ARGV[1]

local members = redis.call('SMEMBERS', setKey)
local removed = 0
for _, member in ipairs(members) do
  local exists = redis.call('EXISTS', prefix .. member)
  if exists == 0 then
    redis.call('SREM', setKey, member)
    removed = removed + 1
  end
end
return removed
