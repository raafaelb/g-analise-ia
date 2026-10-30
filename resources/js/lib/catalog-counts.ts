type Counts = {all:number|null;mine:number|null;published:number|null};

export function catalogAppCounts(capability:unknown, resources:unknown):Counts {
    const unknown:Counts = {all:null,mine:null,published:null};
    if (!capability || typeof capability !== 'object' || !('status' in capability) || capability.status !== 'available' || !('count' in capability) || !Array.isArray(resources) || capability.count !== resources.length) return unknown;
    if (!resources.every(row => row && typeof row === 'object' && typeof row.state === 'string')) return unknown;
    const knownStates = resources.every(row => row.state === 'draft' || row.state === 'published');
    return {all:resources.length,mine:knownStates?resources.filter(row => row.state === 'draft').length:null,published:knownStates?resources.filter(row => row.state === 'published').length:null};
}
