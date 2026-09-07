# Board Control Center

The advertising domain behind a network of digital billboards: what plays where,
how much airtime each play consumes, and who is owed proof that it aired.

## Language

### Airtime and inventory

**Spot**:
The unit of airtime sold and consumed. Its length is a single global setting,
not a per-billboard one.
_Avoid_: Token, slot, credit

**Play**:
One airing of an Asset on one Billboard. Distinct from Spot: a long Asset costs
several Spots in a single Play.
_Avoid_: Impression, airing, showing

**Footprint**:
How many Spots one Play of an Asset costs, derived from its duration. Still
media costs one; a clip four Spots long costs four.

**Cap**:
A ceiling on consumption over a period — per hour or per day, on an Asset by
Play or on a Loop by Spot. Reaching one makes an Asset ineligible until the
period rolls over.
_Avoid_: Limit, quota, budget

### Content

**Asset**:
A single piece of media booked to air — the thing an advertiser buys airtime
for.
_Avoid_: Creative, file, media, ad

**Loop**:
An ordered rotation of Assets that a Billboard plays through repeatedly. Carries
the playback rules: its own daily Spot cap, and whether it is a Fallback.
_Avoid_: Playlist, rotation, schedule

**Folder**:
A container that groups Loops and Assets for the operator's own organisation.
Nests inside other Folders. Purely organisational — it holds no playback
meaning.
_Avoid_: Directory, category

**Fallback**:
A Loop reserved for unsold airtime, played to fill Spots that no booked Asset is
eligible for. Its Assets are Fallback Assets.
_Avoid_: Filler, default, house ad, PSA

**Campaign**:
An advertiser's booking of an Asset across a date range.

**Flight window**:
The date range a Campaign is bookable between. Outside it the Asset is
ineligible.
_Avoid_: Campaign period, run dates

**Playback window**:
A time of day an Asset is booked to air near, honoured within a tolerance.
Separate from the Flight window, which is about dates.
_Avoid_: Daypart, time slot

**Conflict**:
A pair of Assets that must never air back-to-back — competing advertisers who
have each paid not to be adjacent to the other.

**Geo zone**:
A geographic label on a Billboard describing where it physically stands.

**Geo campaign**:
The geographic targeting label on an Asset, matched against a Billboard's Geo
zone.

### Hardware

**Billboard**:
One physical advertising display in the network, with its own timezone, active
hours and playback history.
_Avoid_: Device, board, screen, panel

**Player**:
The software running on a Billboard that plays its Queue and reports what aired.
Keeps playing through a dead network.

**Freeze**:
Suspending a Billboard so it is served no Assets and airs nothing, without
decommissioning it.
_Avoid_: Pause, disable, blackout

**Heartbeat**:
A Billboard reporting that it is alive and reachable.

### Scheduling

**Queue**:
The ordered run of Plays a Billboard will air next, built from its eligible
Assets.
_Avoid_: Timeline, playlist, run order

**Eligibility**:
Whether an Asset may take the next Spot on a given Billboard right now —
assignment, Caps, Flight window, Playback window and Conflicts all decide it.

**Assignment**:
Which Billboards a Loop or Asset may air on. A global Loop or Asset airs on all
of them.

**Override**:
An Asset an operator pushes to the front of one Billboard's Queue, jumping the
normal rotation. Consumed once, then gone.
_Avoid_: Play next, interrupt, takeover, priority play

**Sync**:
A Billboard pulling its current eligible Assets, rules and pending Overrides
from the server.

### Settlement

**Playback log**:
An immutable record that a Play happened: which Asset, which Billboard, when,
and how many Spots it cost. The billing record of truth.
_Avoid_: Play record, event, impression log

**Reconcile**:
Accepting a Billboard's stored-up Playback logs after it has been offline,
without double-charging Spots for anything already on the books.

**Fallback spot record**:
The sales state of one Fallback Loop's airtime on one Billboard for one date:
either available or sold.

**Vault**:
The delivery surface where an advertiser collects proof their Asset aired,
without an account on the system.

**Share link**:
A single expiring Vault link, opened with a PIN, optionally usable only once.
_Avoid_: Proof link, public link, magic link
