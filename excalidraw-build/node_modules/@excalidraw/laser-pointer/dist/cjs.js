
function $parcel$exportWildcard(dest, source) {
  Object.keys(source).forEach(function(key) {
    if (key === 'default' || key === '__esModule' || Object.prototype.hasOwnProperty.call(dest, key)) {
      return;
    }

    Object.defineProperty(dest, key, {
      enumerable: true,
      get: function get() {
        return source[key];
      }
    });
  });

  return dest;
}

function $parcel$export(e, n, v, s) {
  Object.defineProperty(e, n, {get: v, set: s, enumerable: true, configurable: true});
}
var $a05669264f67e39b$exports = {};

$parcel$export($a05669264f67e39b$exports, "LaserPointer", () => $a05669264f67e39b$export$6531021b3bf36eae);
function $7635cb696243d7e2$export$e16d8520af44a096([ax, ay, ar], [bx, by, br]) {
    return [
        ax + bx,
        ay + by,
        ar + br
    ];
}
function $7635cb696243d7e2$export$f93b5905241a7cca([ax, ay, ar], [bx, by, br]) {
    return [
        ax - bx,
        ay - by,
        ar - br
    ];
}
function $7635cb696243d7e2$export$1c8884ee32bdf443([x, y, r], s) {
    return [
        x * s,
        y * s,
        r * s
    ];
}
function $7635cb696243d7e2$export$1991ecd29cc92c6b([x, y, r]) {
    return [
        x / Math.sqrt(x ** 2 + y ** 2),
        y / Math.sqrt(x ** 2 + y ** 2),
        r
    ];
}
function $7635cb696243d7e2$export$855df823ceb9b81b([x, y, r], rad) {
    return [
        Math.cos(rad) * x - Math.sin(rad) * y,
        Math.sin(rad) * x + Math.cos(rad) * y,
        r
    ];
}
function $7635cb696243d7e2$export$92d8e23769117a5e(a, b, t) {
    return $7635cb696243d7e2$export$e16d8520af44a096(a, $7635cb696243d7e2$export$1c8884ee32bdf443($7635cb696243d7e2$export$f93b5905241a7cca(b, a), t));
}
function $7635cb696243d7e2$export$3a89f8d6f6bf6c9f(a, b, t) {
    return a + (b - a) * t;
}
function $7635cb696243d7e2$export$944b09d2ad10b378(p, p1, p2) {
    return Math.atan2(p2[1] - p[1], p2[0] - p[0]) - Math.atan2(p1[1] - p[1], p1[0] - p[0]);
}
function $7635cb696243d7e2$export$3c05aaf71f141f73(a) {
    return Math.atan2(Math.sin(a), Math.cos(a));
}
function $7635cb696243d7e2$export$1b9bab504faa9305([x, y]) {
    return Math.sqrt(x ** 2 + y ** 2);
}
function $7635cb696243d7e2$export$6c4a311cc157c764([ax, ay], [bx, by]) {
    return Math.sqrt((bx - ax) ** 2 + (by - ay) ** 2);
}
function $7635cb696243d7e2$export$46f6d3e1b4bd7c8b(point, direction, radius) {
    return [
        $7635cb696243d7e2$export$e16d8520af44a096(point, $7635cb696243d7e2$export$1c8884ee32bdf443($7635cb696243d7e2$export$1991ecd29cc92c6b($7635cb696243d7e2$export$855df823ceb9b81b(direction, Math.PI / 2)), radius)),
        $7635cb696243d7e2$export$e16d8520af44a096(point, $7635cb696243d7e2$export$1c8884ee32bdf443($7635cb696243d7e2$export$1991ecd29cc92c6b($7635cb696243d7e2$export$855df823ceb9b81b(direction, -Math.PI / 2)), radius))
    ];
}
function $7635cb696243d7e2$export$ccb1b4dfbd3f36a7(ps) {
    if (ps.length < 2) return 0;
    let len = 0;
    for(let i = 1; i <= ps.length - 1; i++)len += $7635cb696243d7e2$export$6c4a311cc157c764(ps[i - 1], ps[i]);
    len += $7635cb696243d7e2$export$6c4a311cc157c764(ps[ps.length - 2], ps[ps.length - 1]);
    return len;
}
const $7635cb696243d7e2$export$7d15b64cf5a3a4c4 = (v, min, max)=>Math.max(min, Math.min(max, v));
function $7635cb696243d7e2$export$548cc43590159af6(p3, p1, p2) {
    const sMag = $7635cb696243d7e2$export$6c4a311cc157c764(p1, p2);
    if (sMag === 0) return $7635cb696243d7e2$export$6c4a311cc157c764(p3, p1);
    const u = $7635cb696243d7e2$export$7d15b64cf5a3a4c4(((p3[0] - p1[0]) * (p2[0] - p1[0]) + (p3[1] - p1[1]) * (p2[1] - p1[1])) / sMag ** 2, 0, 1);
    const pi = [
        p1[0] + u * (p2[0] - p1[0]),
        p1[1] + u * (p2[1] - p1[1]),
        p3[2]
    ];
    return $7635cb696243d7e2$export$6c4a311cc157c764(pi, p3);
}



function $dd0ccf2ce926af1c$export$ef693d1572e64fb8(points, epsilon) {
    if (epsilon === 0) return points;
    if (points.length <= 2) return points;
    const first = points[0];
    const last = points[points.length - 1];
    const [maxDistance, maxIndex] = points.reduce(([maxDistance, maxIndex], point, index)=>{
        const distance = (0, $7635cb696243d7e2$export$548cc43590159af6)(point, first, last);
        return distance > maxDistance ? [
            distance,
            index
        ] : [
            maxDistance,
            maxIndex
        ];
    }, [
        0,
        -1
    ]);
    if (maxDistance >= epsilon) {
        const maxIndexPoint = points[maxIndex];
        return [
            ...$dd0ccf2ce926af1c$export$ef693d1572e64fb8([
                first,
                ...points.slice(1, maxIndex),
                maxIndexPoint
            ], epsilon).slice(0, -1),
            maxIndexPoint,
            ...$dd0ccf2ce926af1c$export$ef693d1572e64fb8([
                maxIndexPoint,
                ...points.slice(maxIndex, -1),
                last
            ], epsilon).slice(1)
        ];
    } else return [
        first,
        last
    ];
}


class $a05669264f67e39b$export$6531021b3bf36eae {
    static{
        this.defaults = {
            size: 2,
            streamline: 0.45,
            simplify: 0.1,
            simplifyPhase: "output",
            keepHead: false,
            sizeMapping: ()=>1
        };
    }
    static{
        this.constants = {
            cornerDetectionMaxAngle: 75,
            cornerDetectionVariance: (s)=>s > 35 ? 0.5 : 1,
            maxTailLength: 50
        };
    }
    constructor(options){
        this.originalPoints = [];
        this.stablePoints = [];
        this.tailPoints = [];
        this.isFresh = true;
        this.options = Object.assign({}, $a05669264f67e39b$export$6531021b3bf36eae.defaults, options);
    }
    get lastPoint() {
        return this.tailPoints[this.tailPoints.length - 1] ?? this.stablePoints[this.stablePoints.length - 1];
    }
    addPoint(point) {
        const lastPoint = this.originalPoints[this.originalPoints.length - 1];
        if (lastPoint && lastPoint[0] === point[0] && lastPoint[1] === point[1]) return;
        this.originalPoints.push(point);
        if (this.isFresh) {
            this.isFresh = false;
            this.stablePoints.push(point);
            return;
        }
        if (this.options.streamline > 0) point = $7635cb696243d7e2$export$92d8e23769117a5e(this.lastPoint, point, 1 - this.options.streamline);
        this.tailPoints.push(point);
        if ($7635cb696243d7e2$export$ccb1b4dfbd3f36a7(this.tailPoints) > $a05669264f67e39b$export$6531021b3bf36eae.constants.maxTailLength) this.stabilizeTail();
    }
    close() {
        this.stabilizeTail();
    }
    stabilizeTail() {
        if (this.options.simplify > 0 && this.options.simplifyPhase == "tail") throw new Error("Not implemented yet");
        else {
            this.stablePoints.push(...this.tailPoints);
            this.tailPoints = [];
        }
    }
    getSize(sizeOverride, pressure, index, totalLength, runningLength) {
        return (sizeOverride ?? this.options.size) * this.options.sizeMapping({
            pressure: pressure,
            runningLength: runningLength,
            currentIndex: index,
            totalLength: totalLength
        });
    }
    getStrokeOutline(sizeOverride) {
        if (this.isFresh) return [];
        let points = [
            ...this.stablePoints,
            ...this.tailPoints
        ];
        if (this.options.simplify > 0 && this.options.simplifyPhase === "input") points = (0, $dd0ccf2ce926af1c$export$ef693d1572e64fb8)(points, this.options.simplify);
        const len = points.length;
        if (len === 0) return [];
        if (len === 1) {
            const c = points[0];
            const size = this.getSize(sizeOverride, c[2], 0, len, 0);
            if (size < 0.5) return [];
            const ps = [];
            for(let theta = 0; theta <= Math.PI * 2; theta += Math.PI / 16)ps.push($7635cb696243d7e2$export$e16d8520af44a096(c, $7635cb696243d7e2$export$1c8884ee32bdf443($7635cb696243d7e2$export$855df823ceb9b81b([
                1,
                0,
                0
            ], theta), size)));
            ps.push($7635cb696243d7e2$export$e16d8520af44a096(c, $7635cb696243d7e2$export$1c8884ee32bdf443([
                1,
                0,
                0
            ], this.getSize(sizeOverride, c[2], 0, len, 0))));
            return ps;
        }
        if (len === 2) {
            const c = points[0];
            const n = points[1];
            const cSize = this.getSize(sizeOverride, c[2], 0, len, 0);
            const nSize = this.getSize(sizeOverride, n[2], 0, len, 0);
            if (cSize < 0.5 || nSize < 0.5) return [];
            const ps = [];
            const pAngle = $7635cb696243d7e2$export$944b09d2ad10b378(c, [
                c[0],
                c[1] - 100,
                c[2]
            ], n);
            for(let theta = pAngle; theta <= Math.PI + pAngle; theta += Math.PI / 16)ps.push($7635cb696243d7e2$export$e16d8520af44a096(c, $7635cb696243d7e2$export$1c8884ee32bdf443($7635cb696243d7e2$export$855df823ceb9b81b([
                1,
                0,
                0
            ], theta), cSize)));
            for(let theta = Math.PI + pAngle; theta <= Math.PI * 2 + pAngle; theta += Math.PI / 16)ps.push($7635cb696243d7e2$export$e16d8520af44a096(n, $7635cb696243d7e2$export$1c8884ee32bdf443($7635cb696243d7e2$export$855df823ceb9b81b([
                1,
                0,
                0
            ], theta), nSize)));
            ps.push(ps[0]);
            return ps;
        }
        const forwardPoints = [];
        const backwardPoints = [];
        let speed = 0;
        let prevSpeed = 0;
        let visibleStartIndex = 0;
        let runningLength = 0;
        for(let i = 1; i < len - 1; i++){
            const p = points[i - 1], c = points[i], n = points[i + 1];
            let pressure = c[2];
            const d = $7635cb696243d7e2$export$6c4a311cc157c764(p, c);
            runningLength += d;
            speed = prevSpeed + (d - prevSpeed) * 0.2;
            const cSize = this.getSize(sizeOverride, pressure, i, len, runningLength);
            if (cSize === 0) {
                visibleStartIndex = i + 1;
                continue;
            }
            const dirPC = $7635cb696243d7e2$export$1991ecd29cc92c6b($7635cb696243d7e2$export$f93b5905241a7cca(p, c));
            const dirNC = $7635cb696243d7e2$export$1991ecd29cc92c6b($7635cb696243d7e2$export$f93b5905241a7cca(n, c));
            const p1dirPC = $7635cb696243d7e2$export$855df823ceb9b81b(dirPC, Math.PI / 2);
            const p2dirPC = $7635cb696243d7e2$export$855df823ceb9b81b(dirPC, -Math.PI / 2);
            const p1dirNC = $7635cb696243d7e2$export$855df823ceb9b81b(dirNC, Math.PI / 2);
            const p2dirNC = $7635cb696243d7e2$export$855df823ceb9b81b(dirNC, -Math.PI / 2);
            const p1PC = $7635cb696243d7e2$export$e16d8520af44a096(c, $7635cb696243d7e2$export$1c8884ee32bdf443(p1dirPC, cSize));
            const p2PC = $7635cb696243d7e2$export$e16d8520af44a096(c, $7635cb696243d7e2$export$1c8884ee32bdf443(p2dirPC, cSize));
            const p1NC = $7635cb696243d7e2$export$e16d8520af44a096(c, $7635cb696243d7e2$export$1c8884ee32bdf443(p1dirNC, cSize));
            const p2NC = $7635cb696243d7e2$export$e16d8520af44a096(c, $7635cb696243d7e2$export$1c8884ee32bdf443(p2dirNC, cSize));
            const ftdir = $7635cb696243d7e2$export$e16d8520af44a096(p1dirPC, p2dirNC);
            const btdir = $7635cb696243d7e2$export$e16d8520af44a096(p2dirPC, p1dirNC);
            const paPC = $7635cb696243d7e2$export$e16d8520af44a096(c, $7635cb696243d7e2$export$1c8884ee32bdf443($7635cb696243d7e2$export$1b9bab504faa9305(ftdir) === 0 ? dirPC : $7635cb696243d7e2$export$1991ecd29cc92c6b(ftdir), cSize));
            const paNC = $7635cb696243d7e2$export$e16d8520af44a096(c, $7635cb696243d7e2$export$1c8884ee32bdf443($7635cb696243d7e2$export$1b9bab504faa9305(btdir) === 0 ? dirNC : $7635cb696243d7e2$export$1991ecd29cc92c6b(btdir), cSize));
            const cAngle = $7635cb696243d7e2$export$3c05aaf71f141f73($7635cb696243d7e2$export$944b09d2ad10b378(c, p, n));
            const D_ANGLE = $a05669264f67e39b$export$6531021b3bf36eae.constants.cornerDetectionMaxAngle / 180 * Math.PI * $a05669264f67e39b$export$6531021b3bf36eae.constants.cornerDetectionVariance(speed);
            if (Math.abs(cAngle) < D_ANGLE) {
                const tAngle = Math.abs($7635cb696243d7e2$export$3c05aaf71f141f73(Math.PI - cAngle)) // turn angle
                ;
                if (tAngle === 0) continue;
                if (cAngle < 0) {
                    backwardPoints.push(p2PC, paNC);
                    for(let theta = 0; theta <= tAngle; theta += tAngle / 4)forwardPoints.push($7635cb696243d7e2$export$e16d8520af44a096(c, $7635cb696243d7e2$export$855df823ceb9b81b($7635cb696243d7e2$export$1c8884ee32bdf443(p1dirPC, cSize), theta)));
                    for(let theta = tAngle; theta >= 0; theta -= tAngle / 4)backwardPoints.push($7635cb696243d7e2$export$e16d8520af44a096(c, $7635cb696243d7e2$export$855df823ceb9b81b($7635cb696243d7e2$export$1c8884ee32bdf443(p1dirPC, cSize), theta)));
                    backwardPoints.push(paNC, p1NC);
                } else {
                    forwardPoints.push(p1PC, paPC);
                    for(let theta = 0; theta <= tAngle; theta += tAngle / 4)backwardPoints.push($7635cb696243d7e2$export$e16d8520af44a096(c, $7635cb696243d7e2$export$855df823ceb9b81b($7635cb696243d7e2$export$1c8884ee32bdf443(p1dirPC, -cSize), -theta)));
                    for(let theta = tAngle; theta >= 0; theta -= tAngle / 4)forwardPoints.push($7635cb696243d7e2$export$e16d8520af44a096(c, $7635cb696243d7e2$export$855df823ceb9b81b($7635cb696243d7e2$export$1c8884ee32bdf443(p1dirPC, -cSize), -theta)));
                    forwardPoints.push(paPC, p2NC);
                }
            } else {
                forwardPoints.push(paPC);
                backwardPoints.push(paNC);
            }
            prevSpeed = speed;
        }
        if (visibleStartIndex >= len - 2) {
            if (this.options.keepHead) {
                const c = points[len - 1];
                const ps = [];
                for(let theta = 0; theta <= Math.PI * 2; theta += Math.PI / 16)ps.push($7635cb696243d7e2$export$e16d8520af44a096(c, $7635cb696243d7e2$export$1c8884ee32bdf443($7635cb696243d7e2$export$855df823ceb9b81b([
                    1,
                    0,
                    0
                ], theta), this.options.size)));
                ps.push($7635cb696243d7e2$export$e16d8520af44a096(c, $7635cb696243d7e2$export$1c8884ee32bdf443([
                    1,
                    0,
                    0
                ], this.options.size)));
                return ps;
            } else return [];
        }
        const first = points[visibleStartIndex];
        const second = points[visibleStartIndex + 1];
        const penultimate = points[len - 2];
        const ultimate = points[len - 1];
        const dirFS = $7635cb696243d7e2$export$1991ecd29cc92c6b($7635cb696243d7e2$export$f93b5905241a7cca(second, first));
        const dirPU = $7635cb696243d7e2$export$1991ecd29cc92c6b($7635cb696243d7e2$export$f93b5905241a7cca(penultimate, ultimate));
        const ppdirFS = $7635cb696243d7e2$export$855df823ceb9b81b(dirFS, -Math.PI / 2);
        const ppdirPU = $7635cb696243d7e2$export$855df823ceb9b81b(dirPU, Math.PI / 2);
        const startCapSize = this.getSize(sizeOverride, first[2], 0, len, 0);
        const startCap = [];
        const endCapSize = this.options.keepHead ? this.options.size : this.getSize(sizeOverride, penultimate[2], len - 2, len, runningLength);
        const endCap = [];
        if (startCapSize > 1) {
            for(let theta = 0; theta <= Math.PI; theta += Math.PI / 16)startCap.unshift($7635cb696243d7e2$export$e16d8520af44a096(first, $7635cb696243d7e2$export$855df823ceb9b81b($7635cb696243d7e2$export$1c8884ee32bdf443(ppdirFS, startCapSize), -theta)));
            startCap.unshift($7635cb696243d7e2$export$e16d8520af44a096(first, $7635cb696243d7e2$export$1c8884ee32bdf443(ppdirFS, -startCapSize)));
        } else startCap.push(first);
        for(let theta = 0; theta <= Math.PI * 3; theta += Math.PI / 16)endCap.push($7635cb696243d7e2$export$e16d8520af44a096(ultimate, $7635cb696243d7e2$export$855df823ceb9b81b($7635cb696243d7e2$export$1c8884ee32bdf443(ppdirPU, -endCapSize), -theta)));
        const strokeOutline = [
            ...startCap,
            ...forwardPoints,
            ...endCap.reverse(),
            ...backwardPoints.reverse()
        ];
        if (startCap.length > 0) strokeOutline.push(startCap[0]);
        if (this.options.simplify > 0 && this.options.simplifyPhase === "output") return (0, $dd0ccf2ce926af1c$export$ef693d1572e64fb8)(strokeOutline, this.options.simplify);
        return strokeOutline;
    }
}


$parcel$exportWildcard(module.exports, $a05669264f67e39b$exports);


//# sourceMappingURL=cjs.js.map
