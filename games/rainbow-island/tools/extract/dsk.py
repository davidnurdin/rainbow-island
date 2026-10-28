import sys, struct
def load(path):
    d=open(path,'rb').read()
    ntr=d[0x30]; nsides=d[0x31]; tsize=struct.unpack('<H',d[0x32:0x34])[0]
    tracks={}
    off=0x100
    for t in range(ntr*nsides):
        th=d[off:off+0x100]
        assert th[:10]==b'Track-Info',(t,th[:12])
        tr=th[0x10]; side=th[0x11]; ssz=th[0x14]; ns=th[0x15]; gap=th[0x16]; fill=th[0x17]
        secs=[]; p=off+0x100
        for i in range(ns):
            C,H,R,N,s1,s2=th[0x18+i*8:0x18+i*8+6]
            size=128<<N
            secs.append(dict(C=C,H=H,R=R,N=N,st1=s1,st2=s2,data=d[p:p+size]))
            p+=size
        tracks[(tr,side)]=secs
        off+=tsize
    return tracks
if __name__=='__main__':
    t=load(sys.argv[1])
    for k,secs in sorted(t.items()):
        print(k,[(hex(s['R']),s['N'],hex(s['st1']),hex(s['st2'])) for s in secs])
